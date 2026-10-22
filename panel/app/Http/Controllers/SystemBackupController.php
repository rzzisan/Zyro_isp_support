<?php

namespace App\Http\Controllers;

use App\Models\SystemBackup;
use App\Models\SystemBackupSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google Drive sign-in for the whole-Desk backups (super admin only), the recovery sheet and prepared downloads.
 * Scope drive.file: Desk sees only the files it made (the ZyroDesk-Backup folder), nothing else in the Drive.
 */
class SystemBackupController extends Controller
{
    public const SCOPES = 'https://www.googleapis.com/auth/drive.file openid email';

    public const FOLDER = 'ZyroDesk-Backup';

    private function guard(): void
    {
        abort_unless((bool) Auth::user()?->is_super_admin, 403);
    }

    public static function redirectUri(): string
    {
        return url('/system-backup/google/callback');
    }

    public function connect(Request $request): RedirectResponse
    {
        $this->guard();
        $s = SystemBackupSetting::current();
        if (blank($s->google_client_id) || blank($s->google_client_secret)) {
            return redirect(\App\Filament\Super\Pages\SystemBackupSettings::getUrl(panel: 'super'))->with('backup_error', __('আগে OAuth client ID আর secret সেভ করুন'));
        }
        $state = Str::random(40);
        $request->session()->put('system_backup_oauth_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $s->google_client_id, 'redirect_uri' => static::redirectUri(), 'response_type' => 'code',
            'scope' => static::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true',
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->guard();
        $back = \App\Filament\Super\Pages\SystemBackupSettings::getUrl(panel: 'super');
        $state = $request->session()->pull('system_backup_oauth_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state'))) {
            return redirect($back)->with('backup_error', __('সংযোগ মেলেনি, আবার চেষ্টা করুন'));
        }
        if ($request->filled('error') || blank($request->query('code'))) {
            return redirect($back)->with('backup_error', __('Google অনুমতি দেয়নি: :e', ['e' => (string) $request->query('error', '-')]));
        }
        $s = SystemBackupSetting::current();
        $token = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'), 'client_id' => $s->google_client_id, 'client_secret' => $s->google_client_secret,
            'redirect_uri' => static::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (! $token->ok() || blank($token->json('refresh_token'))) {
            return redirect($back)->with('backup_error', __('Google থেকে টোকেন পাওয়া যায়নি: :e',
                ['e' => Str::limit((string) ($token->json('error_description') ?? $token->json('error') ?? $token->status()), 150)]));
        }
        if (! str_contains((string) $token->json('scope'), 'drive.file')) {
            return redirect($back)->with('backup_error', __('Google Drive-এর অনুমতির ঘরে টিক দেওয়া হয়নি, আবার সংযোগ দিয়ে টিক দিন'));
        }
        $auth = Http::withToken($token->json('access_token'))->timeout(30);
        $email = $auth->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        // same account: keep the folder (the old backups stay readable); another account starts a new folder
        $folder = $s->google_email === $email ? $s->google_folder_id : null;
        if ($folder && ! $auth->get("https://www.googleapis.com/drive/v3/files/{$folder}", ['fields' => 'id,trashed'])->ok()) {
            $folder = null;
        }
        if (! $folder) {
            $made = $auth->post('https://www.googleapis.com/drive/v3/files', ['name' => static::FOLDER,
                'mimeType' => 'application/vnd.google-apps.folder', 'description' => 'ZyroDesk encrypted backups. Do not edit.']);
            if (! $made->ok()) {
                return redirect($back)->with('backup_error', __('Drive-এ ফোল্ডার বানানো যায়নি: :e', ['e' => Str::limit($made->body(), 150)]));
            }
            $folder = $made->json('id');
            if ($s->google_folder_id) { // backups in the previous account's folder can't be reached from here any more
                SystemBackup::whereIn('kind', ['db', 'full'])->where('pruned', false)->update(['pruned' => true]);
            }
        }
        $s->forceFill(['google_refresh_token' => $token->json('refresh_token'), 'google_email' => $email,
            'google_folder_id' => $folder, 'google_connected_at' => now(), 'google_error' => null, 'drive_checked_at' => null])->save();

        return redirect($back)->with('backup_ok', __('Google Drive সংযুক্ত: :email', ['email' => $email]));
    }

    public function recovery(): Response
    {
        $this->guard();
        $s = SystemBackupSetting::current();

        return response($s->recoveryText(), 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="zyrodesk-backup-recovery.txt"']);
    }

    public function download(SystemBackup $backup): Response
    {
        $this->guard();
        $path = $backup->download_path;
        $disk = Storage::disk('local');
        abort_unless($backup->kind === 'download' && $path && str_starts_with($path, 'system-backup-downloads/')
            && ! str_contains($path, '..') && $backup->expires_at?->isFuture() && $disk->exists($path), 404);

        return $disk->download($path, basename($path), ['Content-Type' => 'application/x-tar', 'Cache-Control' => 'no-store']);
    }
}
