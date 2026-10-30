<?php

namespace App\Console\Commands;

use App\Models\PbxCdr;
use App\Models\SipSetting;
use App\Services\PbxPortalClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Copies the hotline's call records (CDR) from the PBX web portal into pbx_cdrs, one day per request. Safe to re-run. */
class PullPbxCdrs extends Command
{
    protected $signature = 'pbx:cdr-pull {--company= : only this company id} {--days=30 : how many days back, today included}
        {--from= : first day (Y-m-d, Bangladesh time) instead of --days} {--to= : last day (Y-m-d), default today}';

    protected $description = 'Copy hotline call records from the PBX portal (FusionPBX CSV export) into pbx_cdrs';

    public function handle(): int
    {
        $tz = PbxPortalClient::TZ;
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to'), $tz) : CarbonImmutable::now($tz);
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from'), $tz)
            : $to->subDays(max(1, (int) $this->option('days')) - 1);
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $settings = SipSetting::whereNotNull('portal_url')
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))->get()
            ->filter->portalConfigured();
        $failed = false;
        foreach ($settings as $s) {
            $client = new PbxPortalClient($s->portal_url, $s->portal_username, $s->portal_password);
            $total = 0;
            try {
                for ($day = $from; $day <= $to; $day = $day->addDay()) {
                    $rows = collect($client->cdrs($day, $day->addDay()))->map(fn ($r) => PbxPortalClient::normalize($r))->filter();
                    foreach ($rows as $row) {
                        PbxCdr::updateOrCreate(['company_id' => $s->company_id, 'uuid' => $row['uuid']], $row);
                    }
                    $total += $rows->count();
                    $this->line("company {$s->company_id} {$day->format('Y-m-d')}: {$rows->count()}");
                }
                $s->forceFill(['portal_pulled_at' => now(), 'portal_checked_at' => now(), 'portal_check_ok' => true,
                    'portal_check_message' => "{$from->format('d M')} – {$to->format('d M')}: {$total}টা কল রেকর্ড আনা হয়েছে"])->save();
            } catch (Throwable $e) {
                $failed = true;
                $s->forceFill(['portal_checked_at' => now(), 'portal_check_ok' => false,
                    'portal_check_message' => mb_substr($e->getMessage(), 0, 300)])->save();
                $this->error("company {$s->company_id}: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
