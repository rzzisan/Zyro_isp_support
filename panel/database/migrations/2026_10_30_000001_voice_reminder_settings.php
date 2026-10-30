<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Voice reminder calls by themselves (App\Support\VoiceReminders, `php artisan voice:reminders` every 5 minutes):
// each company's settings, and which disabled lines were already off when it started watching (no call for those).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('test_mode')->default(true);
            $table->string('test_number', 20)->nullable();
            $table->unsignedSmallInteger('test_daily_max')->default(3);
            $table->boolean('reminder_on')->default(true);
            $table->foreignId('reminder_flow_id')->nullable()->constrained('voice_flows')->nullOnDelete();
            $table->string('reminder_days', 30)->default('2,0');
            $table->boolean('due_only')->default(true);
            $table->boolean('line_off_on')->default(true);
            $table->foreignId('line_off_flow_id')->nullable()->constrained('voice_flows')->nullOnDelete();
            $table->unsignedSmallInteger('line_off_delay')->default(60);      // minutes after we see the line off
            $table->unsignedSmallInteger('line_off_days')->default(2);        // days it is tried while still off
            $table->string('window_from', 5)->default('10:00');
            $table->string('window_to', 5)->default('20:00');
            $table->boolean('skip_jumma')->default(true);                     // Friday 12:30-14:30
            $table->unsignedSmallInteger('max_calls')->default(1);
            $table->unsignedSmallInteger('retry_minutes')->default(120);
            $table->unsignedSmallInteger('max_tries')->default(2);            // dials per call (first + retries)
            $table->decimal('minute_rate', 6, 2)->default(0.5);
            $table->timestamps();
        });
        Schema::table('voice_reminder_calls', function (Blueprint $table) {
            $table->text('package')->nullable()->change();    // a planned call gets its package right before it goes
            $table->unsignedSmallInteger('tries')->default(0);
            $table->string('dedupe', 120)->nullable();        // one call per customer + reason (bill:<id>:<expire>:<n>, off:<id>:<day>)
            $table->date('ref_date')->nullable();             // the day it is meant for (Bangladesh)
            $table->string('reason')->nullable();             // why it was skipped
            $table->unique(['company_id', 'dedupe']);
        });
        Schema::create('voice_line_watch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->timestamp('off_seen_at');
            $table->boolean('baseline')->default(false);      // already off when watching started: never called
            $table->unique(['company_id', 'customer_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_line_watch');
        Schema::table('voice_reminder_calls', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'dedupe']);
            $table->dropColumn(['tries', 'dedupe', 'ref_date', 'reason']);
        });
        Schema::dropIfExists('voice_reminder_settings');
    }
};
