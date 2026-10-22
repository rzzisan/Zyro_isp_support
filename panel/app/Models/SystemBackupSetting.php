<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Whole-Desk backup settings (one row, id 1), edited on /super → সিস্টেম ব্যাকআপ and read by engine/system_backup.py.
 * Google secrets and the recovery key (rclone crypt password) are encrypted with APP_KEY; the engine decrypts them the same way.
 */
class SystemBackupSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['google_client_secret', 'google_refresh_token', 'crypt_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'include_files' => 'boolean', 'include_secrets' => 'boolean', 'include_config' => 'boolean',
            'weekly_restore_test' => 'boolean', 'google_client_secret' => 'encrypted', 'google_refresh_token' => 'encrypted',
            'crypt_key' => 'encrypted', 'google_connected_at' => 'datetime', 'drive_checked_at' => 'datetime',
            'crypt_key_confirmed_at' => 'datetime', 'stale_alert_at' => 'datetime'];
    }

    public static function current(): self
    {
        $s = static::query()->first() ?? static::create([])->refresh(); // refresh: the column defaults
        if (blank($s->crypt_key)) { // made once; changing it later would make every older backup unreadable
            $s->forceFill(['crypt_key' => static::newKey()])->save();
        }

        return $s;
    }

    /** 6 groups of 5 (A-Z, 2-9, no look-alikes): easy to write on paper, ~150 bits. */
    public static function newKey(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 6))->map(fn () => collect(range(1, 5))->map(fn () => $abc[random_int(0, strlen($abc) - 1)])->implode(''))
            ->implode('-');
    }

    public function connected(): bool
    {
        return filled($this->google_refresh_token) && filled($this->google_folder_id);
    }

    /** Everything needed to restore on a new server besides the Google login: saved offline by the super admin. */
    public function recoveryText(): string
    {
        return implode("\n", [
            'ZyroDesk backup recovery sheet',
            'Recovery key: '.$this->crypt_key,
            'Google account: '.($this->google_email ?: '-'),
            'Drive folder id: '.($this->google_folder_id ?: '-'),
            'OAuth client id: '.($this->google_client_id ?: '-'),
            'OAuth client secret: '.($this->google_client_secret ?: '-'),
            'Refresh token: '.($this->google_refresh_token ?: '-'),
            '',
            'Restore: deploy/restore-server.sh on a fresh Ubuntu 24.04 (docs/restore.md).',
            'Without the recovery key no backup can be read.',
        ])."\n";
    }

    public function alertAccount(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class, 'alert_wa_account_id');
    }

    public function fingerprint(): string
    {
        return Str::substr(hash('sha256', (string) $this->crypt_key), 0, 8);
    }
}
