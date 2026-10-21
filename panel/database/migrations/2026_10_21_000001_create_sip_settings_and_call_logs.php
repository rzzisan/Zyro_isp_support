<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the company's hotline SIP extension (e.g. 106 on the cloud PBX); the engine registers it with baresip
        Schema::create('sip_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('server')->nullable();
            $table->unsignedInteger('port')->default(5060);
            $table->string('transport', 10)->default('udp');
            $table->string('username')->nullable();
            $table->string('auth_user')->nullable();
            $table->text('password')->nullable();          // encrypted
            $table->boolean('enabled')->default(false);
            $table->string('status', 250)->nullable();     // written by the engine: registered / the PBX's error
            $table->timestamp('status_at')->nullable();
            $table->timestamps();
        });

        // every incoming hotline call the extension saw ringing (the panel pops the caller up from here)
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('call_ref', 120);
            $table->string('caller', 80);
            $table->string('caller_normalized', 20)->nullable();   // 8801XXXXXXXXX, like billing_customers.mobile_normalized
            $table->string('status', 20)->default('ringing');     // ringing, ended
            $table->string('end_reason', 160)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'call_ref']);
            $table->index(['company_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('sip_settings');
    }
};
