<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support-ticket SMS from the Desk (billing's ticket SMS is never used): template types ticket_open / ticket_solved,
 * gateway settings say whether a new / solved ticket sends one and with which template. Default Bangla templates.
 */
return new class extends Migration
{
    public const DEFAULTS = [
        'ticket_open' => ['টিকিট খোলা', 'প্রিয় {name}, আপনার অভিযোগ গ্রহণ করা হয়েছে। টিকিট নং {ticket_no}, সমস্যা: {problem}। আমাদের টেকনিশিয়ান দ্রুত যোগাযোগ করবেন। {company}'],
        'ticket_solved' => ['টিকিট সমাধান', 'প্রিয় {name}, আপনার টিকিট {ticket_no} এর সমস্যার সমাধান হয়েছে। কোনো সমস্যা থাকলে জানাবেন। {company}'],
    ];

    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('ticket_open_sms')->default(false);
            $table->foreignId('ticket_open_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->boolean('ticket_solved_sms')->default(false);
            $table->foreignId('ticket_solved_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
        });
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::DEFAULTS as $type => [$name, $body]) {
                if (! DB::table('sms_templates')->where('company_id', $companyId)->where('type', $type)->exists()) {
                    DB::table('sms_templates')->insert(['company_id' => $companyId, 'type' => $type, 'name' => $name,
                        'body' => $body, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_open_template_id');
            $table->dropConstrainedForeignId('ticket_solved_template_id');
            $table->dropColumn(['ticket_open_sms', 'ticket_solved_sms']);
        });
    }
};
