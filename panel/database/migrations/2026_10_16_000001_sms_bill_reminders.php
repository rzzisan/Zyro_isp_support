<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bill reminder from the Desk (instead of billing's due SMS): some days before a customer's expire date (billing's
 * BillingLastDate = billing_customers.bill_day), at a set hour, once per billing cycle. Template type bill_reminder.
 */
return new class extends Migration
{
    public const BODY = 'প্রিয় {name}, আপনার ইন্টারনেট সংযোগের মেয়াদ {expire_date} তারিখে শেষ হবে। বকেয়া {due} টাকা। নিরবচ্ছিন্ন সেবার জন্য সময়মতো বিল পরিশোধ করুন। {company}';

    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('reminder_sms')->default(false);
            $table->foreignId('reminder_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->string('reminder_days', 30)->default('3');           // days before the expire date, e.g. "3,1" (0 = that day)
            $table->unsignedSmallInteger('reminder_hour')->default(10);    // Bangladesh time, 0-23
            $table->boolean('reminder_due_only')->default(true);           // only customers with a due
        });
        // one reminder per customer, expire date and "days before": a re-run never sends it again
        Schema::create('sms_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->date('expire_date');
            $table->unsignedSmallInteger('days_before');
            $table->foreignId('message_id')->nullable()->constrained('sms_messages')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'customer_header_id', 'expire_date', 'days_before'], 'sms_reminders_once');
        });
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('sms_templates')->where('company_id', $companyId)->where('type', 'bill_reminder')->exists()) {
                DB::table('sms_templates')->insert(['company_id' => $companyId, 'type' => 'bill_reminder', 'name' => 'বিল রিমাইন্ডার',
                    'body' => self::BODY, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_reminders');
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reminder_template_id');
            $table->dropColumn(['reminder_sms', 'reminder_days', 'reminder_hour', 'reminder_due_only']);
        });
    }
};
