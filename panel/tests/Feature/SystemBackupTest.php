<?php

namespace Tests\Feature;

use App\Filament\Super\Pages\SystemBackupSettings;
use App\Filament\Super\Resources\SystemBackups\Pages\ManageSystemBackups;
use App\Models\Company;
use App\Models\SystemBackup;
use App\Models\SystemBackupSetting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Whole-Desk backups on /super: super admin only, Google sign-in, recovery key gate, queueing, downloads. */
class SystemBackupTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function connect(): SystemBackupSetting
    {
        $s = SystemBackupSetting::current();
        $s->forceFill(['google_client_id' => 'cid.apps.googleusercontent.com', 'google_client_secret' => 'csecret',
            'google_refresh_token' => 'rt', 'google_email' => 'me@gmail.com', 'google_folder_id' => 'F1',
            'google_connected_at' => now()])->save();

        return $s;
    }

    public function test_only_super_admins_reach_backups(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)->get('/super/system-backups')->assertForbidden();
        $this->actingAs($owner)->get('/system-backup/recovery.txt')->assertForbidden();
        $this->actingAs($owner)->get('/system-backup/google/connect')->assertForbidden();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/super/system-backups')->assertOk()->assertSee('Google Drive এখনো সংযুক্ত নয়');
        $this->actingAs($admin)->get('/super/system-backup-settings')->assertOk();
    }

    public function test_recovery_key_is_made_once_and_kept(): void
    {
        $s = SystemBackupSetting::current();
        $this->assertMatchesRegularExpression('/^([A-Z2-9]{5}-){5}[A-Z2-9]{5}$/', $s->crypt_key);
        $this->assertSame($s->crypt_key, SystemBackupSetting::current()->crypt_key);
        $this->assertNotSame($s->crypt_key, \DB::table('system_backup_settings')->value('crypt_key')); // encrypted at rest

        $this->connect();
        $text = $this->actingAs($this->superAdmin())->get('/system-backup/recovery.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Recovery key: '.$s->crypt_key, $text);
        $this->assertStringContainsString('Drive folder id: F1', $text);
    }

    public function test_google_sign_in_stores_token_and_makes_folder(): void
    {
        $admin = $this->superAdmin();
        SystemBackupSetting::current()->forceFill(['google_client_id' => 'cid.apps.googleusercontent.com', 'google_client_secret' => 'csecret'])->save();

        $res = $this->actingAs($admin)->get('/system-backup/google/connect');
        $res->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $res->headers->get('location'));
        parse_str(parse_url($res->headers->get('location'), PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('drive.file', $q['scope']);
        $this->assertStringNotContainsString('auth/drive ', $q['scope'].' '); // never the full-Drive scope

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-1',
                'scope' => 'https://www.googleapis.com/auth/drive.file openid email', 'expires_in' => 3599]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'zisan@gmail.com']),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'FOLDER9']),
        ]);
        $this->actingAs($admin)->get('/system-backup/google/callback?state=wrong&code=c')->assertRedirect();
        $this->assertNull(SystemBackupSetting::current()->google_refresh_token);

        $this->actingAs($admin)->withSession(['system_backup_oauth_state' => 'st'])
            ->get('/system-backup/google/callback?state=st&code=c')->assertRedirect()->assertSessionHas('backup_ok');
        $s = SystemBackupSetting::current();
        $this->assertSame('rt-1', $s->google_refresh_token);
        $this->assertSame('zisan@gmail.com', $s->google_email);
        $this->assertSame('FOLDER9', $s->google_folder_id);
        $this->assertTrue($s->connected());
        Http::assertSent(fn ($r) => $r->url() === 'https://www.googleapis.com/drive/v3/files' && $r['name'] === 'ZyroDesk-Backup');
    }

    public function test_auto_backup_needs_drive_and_saved_key(): void
    {
        $this->actingAs($this->superAdmin());
        Livewire::test(SystemBackupSettings::class)->fillForm(['enabled' => true, 'key_saved' => true])->call('save')->assertHasNoFormErrors();
        $this->assertFalse(SystemBackupSetting::current()->enabled); // not connected yet

        $this->connect();
        Livewire::test(SystemBackupSettings::class)->fillForm(['enabled' => true, 'key_saved' => false])->call('save');
        $this->assertFalse(SystemBackupSetting::current()->enabled); // key not confirmed

        Livewire::test(SystemBackupSettings::class)
            ->fillForm(['enabled' => true, 'key_saved' => true, 'alert_numbers' => "01712-345678, 8801812345678\njunk"])->call('save');
        $s = SystemBackupSetting::current();
        $this->assertTrue($s->enabled);
        $this->assertNotNull($s->crypt_key_confirmed_at);
        $this->assertSame("8801712345678\n8801812345678", $s->alert_numbers);
        $this->assertSame('csecret', $s->google_client_secret); // blank secret field keeps the stored one
    }

    public function test_backup_now_and_restore_test_queue_rows(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        Livewire::test(ManageSystemBackups::class)->callAction('backupNow', ['kind' => 'full']);
        $this->assertSame(0, SystemBackup::count()); // no Drive yet

        $this->connect();
        Livewire::test(ManageSystemBackups::class)->callAction('backupNow', ['kind' => 'full']);
        Livewire::test(ManageSystemBackups::class)->callAction('backupNow', ['kind' => 'full']); // already queued: not twice
        $this->assertSame(1, SystemBackup::where('kind', 'full')->where('status', 'queued')->where('trigger', 'manual')->count());

        $done = SystemBackup::create(['kind' => 'db', 'status' => 'ok', 'remote_dir' => 'runs/2026-10-22_0930-db', 'size' => 100,
            'tier' => 'hourly', 'started_at' => now(), 'finished_at' => now()]);
        Livewire::test(ManageSystemBackups::class)->callAction(TestAction::make('restoreTest')->table($done));
        Livewire::test(ManageSystemBackups::class)->callAction(TestAction::make('prepareDownload')->table($done));
        $this->assertDatabaseHas('system_backups', ['kind' => 'restore_test', 'source_id' => $done->id, 'requested_by' => $admin->id]);
        $this->assertDatabaseHas('system_backups', ['kind' => 'download', 'source_id' => $done->id, 'status' => 'queued']);
    }

    public function test_prepared_download_is_served_until_it_expires(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $path = 'system-backup-downloads/7/zyrodesk-2026-10-22_0330-full.tar';
        Storage::disk('local')->put($path, 'tar');
        $b = SystemBackup::create(['kind' => 'download', 'status' => 'ok', 'download_path' => $path, 'expires_at' => now()->addHour()]);

        $this->actingAs(User::factory()->create())->get("/system-backup/download/{$b->id}")->assertForbidden();
        $res = $this->actingAs($admin)->get("/system-backup/download/{$b->id}")->assertOk();
        $this->assertStringContainsString('zyrodesk-2026-10-22_0330-full.tar', $res->headers->get('content-disposition'));

        $b->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($admin)->get("/system-backup/download/{$b->id}")->assertNotFound();
    }
}
