<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Voice reminder step 4: when the customer's own number is not answered, other numbers from their history
// (customer_alt_numbers, App\Support\AltNumbers); customers who regularly answer the call get the bill reminder SMS
// only as a backup when the call did not reach them; the report.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->boolean('alt_on')->default(true);
            $table->unsignedSmallInteger('alt_max')->default(3);              // other numbers a day per customer
            $table->boolean('sms_saving')->default(true);                     // regular answerers: SMS only as a backup
        });
        Schema::table('voice_reminder_calls', function (Blueprint $table) {
            $table->foreignId('alt_of')->nullable()->constrained('voice_reminder_calls')->nullOnDelete();   // the main call this one tries another number for
            $table->string('alt_number', 20)->nullable();     // the other number (in test mode the call itself goes to the test number)
            $table->string('number_source', 40)->nullable();  // where the other number came from
            $table->boolean('sms_backup')->default(false);    // the bill reminder SMS waits for this call (sent only if it fails)
            $table->timestamp('settled_at')->nullable();      // main call: all tries and other numbers are over
            $table->boolean('reached')->nullable();           // main call: someone answered (this or another number)
            $table->timestamp('backup_sms_at')->nullable();
            $table->foreignId('backup_sms_id')->nullable()->constrained('sms_messages')->nullOnDelete();
            $table->index(['company_id', 'customer_header_id']);
        });
        Schema::create('customer_alt_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->string('number', 20);                     // 8801XXXXXXXXX
            $table->string('sources', 120)->nullable();       // profile,ticket,whatsapp,sms
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('tried_at')->nullable();
            $table->timestamp('reached_at')->nullable();      // a reminder call on this number was answered
            $table->timestamps();
            $table->unique(['company_id', 'customer_header_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_alt_numbers');
        Schema::table('voice_reminder_calls', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'customer_header_id']);
            $table->dropConstrainedForeignId('alt_of');
            $table->dropConstrainedForeignId('backup_sms_id');
            $table->dropColumn(['alt_number', 'number_source', 'sms_backup', 'settled_at', 'reached', 'backup_sms_at']);
        });
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->dropColumn(['alt_on', 'alt_max', 'sms_saving']);
        });
    }
};
