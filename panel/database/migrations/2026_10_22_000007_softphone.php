<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Desk softphone: every member who opens it (browser or app) gets an Asterisk WebRTC endpoint a<id> with its own
// password (engine/voice_bot.py writes them). call_logs: direction in/out, who answered (staff), how long they talked.
// WhatsApp calls now ring for staff first, so the bot waits 20 s instead of 3 (calling_bot_delay).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('password');   // encrypted with APP_KEY
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('direction', 3)->default('in');
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('agent_seconds')->nullable();
        });
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('calling_bot_delay')->default(20)->change();
        });
        DB::table('wa_accounts')->where('calling_bot_delay', 3)->update(['calling_bot_delay' => 20]);
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn(['direction', 'agent_seconds']);
        });
        Schema::dropIfExists('sip_agents');
    }
};
