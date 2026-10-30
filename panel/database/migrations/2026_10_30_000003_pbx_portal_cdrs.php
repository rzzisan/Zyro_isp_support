<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The hotline PBX's web portal (FusionPBX, e.g. pbx.bd / alphapbx): its login, typed in by the Owner and kept encrypted,
// and the call records (CDR) copied from it by `php artisan pbx:cdr-pull` for reports on every hotline call.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->string('portal_url', 200)->nullable();
            $table->string('portal_username', 120)->nullable();
            $table->text('portal_password')->nullable();
            $table->timestamp('portal_checked_at')->nullable();
            $table->boolean('portal_check_ok')->nullable();
            $table->string('portal_check_message', 300)->nullable();
            $table->timestamp('portal_pulled_at')->nullable();
        });
        Schema::create('pbx_cdrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('uuid', 64);
            $table->string('direction', 20)->nullable();
            $table->string('caller_name', 120)->nullable();
            $table->string('caller_number', 40)->nullable();
            $table->string('destination', 60)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->default(0);   // seconds, ringing included
            $table->unsignedInteger('billsec')->default(0);    // seconds talked
            $table->string('hangup_cause', 60)->nullable();
            $table->json('raw')->nullable();                   // the whole CSV row, so later reports can use any column
            $table->timestamps();
            $table->unique(['company_id', 'uuid']);
            $table->index(['company_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_cdrs');
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->dropColumn(['portal_url', 'portal_username', 'portal_password', 'portal_checked_at', 'portal_check_ok',
                'portal_check_message', 'portal_pulled_at']);
        });
    }
};
