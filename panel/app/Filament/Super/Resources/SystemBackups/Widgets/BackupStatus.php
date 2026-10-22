<?php

namespace App\Filament\Super\Resources\SystemBackups\Widgets;

use App\Models\SystemBackup;
use App\Models\SystemBackupSetting;
use Filament\Widgets\Widget;

/** Cards above the backup list: last good backup, next run, Drive space, last restore test. */
class BackupStatus extends Widget
{
    protected string $view = 'filament.super.backup-status';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $s = SystemBackupSetting::current();
        $last = SystemBackup::whereIn('kind', ['db', 'full'])->whereIn('status', ['ok', 'partial'])->latest('started_at')->first();
        $lastFull = SystemBackup::where('kind', 'full')->whereIn('status', ['ok', 'partial'])->latest('started_at')->first();
        $test = SystemBackup::where('kind', 'restore_test')->whereIn('status', ['ok', 'failed'])->latest('started_at')->first();

        return ['s' => $s, 'last' => $last, 'lastFull' => $lastFull, 'test' => $test, 'next' => static::nextRun($s),
            'stale' => $s->enabled && (! $last || $last->started_at->lt(now()->subHours(26)))];
    }

    /** Next scheduled full run (Dhaka), or null when automatic backups are off. */
    public static function nextRun(SystemBackupSetting $s): ?\Illuminate\Support\Carbon
    {
        if (! $s->enabled || ! $s->connected() || ! $s->crypt_key_confirmed_at || $s->google_error) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $s->full_at ?: '03:30'));
        $at = now('Asia/Dhaka')->setTime($h, $m);
        $done = SystemBackup::where('kind', 'full')->where('trigger', 'auto')->whereIn('status', ['ok', 'partial'])
            ->where('created_at', '>=', $at->copy()->utc())->exists();

        return $at->isPast() && $done ? $at->addDay() : ($at->isPast() ? now('Asia/Dhaka') : $at);
    }
}
