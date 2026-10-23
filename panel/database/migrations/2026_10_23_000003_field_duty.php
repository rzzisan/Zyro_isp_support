<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Automatic duty for field technicians: the office place and duty hours (duty starts once they reach the office after the
// start time, ends at the end time or when their work is done), "do it tomorrow" on a ticket, and the app's privacy
// policy (location recording) accepted at sign-in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_accepted_at')->nullable();
        });
        Schema::table('company_user', function (Blueprint $table) {
            $table->date('duty_day')->nullable();   // the Dhaka day duty last started (reached the office)
            $table->timestamp('duty_ended_at')->nullable();
        });
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->decimal('field_office_lat', 10, 7)->nullable();
            $table->decimal('field_office_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('field_office_radius')->default(20);
            $table->string('field_duty_start', 5)->default('10:00');
            $table->string('field_duty_end', 5)->default('17:00');
        });
        Schema::table('billing_tickets', function (Blueprint $table) {
            $table->date('field_later_on')->nullable();   // the technician will do it this day (Dhaka)
        });
    }

    public function down(): void
    {
        Schema::table('billing_tickets', fn (Blueprint $t) => $t->dropColumn('field_later_on'));
        Schema::table('bot_settings', fn (Blueprint $t) => $t->dropColumn(['field_office_lat', 'field_office_lng', 'field_office_radius', 'field_duty_start', 'field_duty_end']));
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn(['duty_day', 'duty_ended_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('privacy_accepted_at'));
    }
};
