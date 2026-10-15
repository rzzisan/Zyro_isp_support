<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SMS after a bill is collected from the Desk: templates get a type (general | bill_paid), the gateway settings say
 * whether "বিল নিন" sends one and with which template. Every company gets one Bangla bill-payment template.
 */
return new class extends Migration
{
    public const BODY = 'প্রিয় {name}, আপনার {months} মাসের বিল {paid} টাকা পরিশোধ হয়েছে। ছাড়: {discount} টাকা, বকেয়া: {due} টাকা। ধন্যবাদ, {company}';

    public function up(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->string('type')->default('general');             // general | bill_paid
        });
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('bill_sms')->default(false);            // "বিল নিন" sends the customer an SMS by default
            $table->foreignId('bill_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
        });
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('sms_templates')->where('company_id', $companyId)->where('type', 'bill_paid')->exists()) {
                DB::table('sms_templates')->insert(['company_id' => $companyId, 'type' => 'bill_paid', 'name' => 'বিল পরিশোধ',
                    'body' => self::BODY, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_template_id');
            $table->dropColumn('bill_sms');
        });
        Schema::table('sms_templates', fn (Blueprint $table) => $table->dropColumn('type'));
    }
};
