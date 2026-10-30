<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A customer whose line is off for the bill calls the hotline or WhatsApp: the line-off flow picks the call up after a
// moment (engine/voice_ivr.py inbound). Its package (the messages' audio) is made ahead for every such line
// (App\Support\VoiceReminders::prepareInbound), so the caller never waits for it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->boolean('inbound_on')->default(false);
            $table->foreignId('inbound_flow_id')->nullable()->constrained('voice_flows')->nullOnDelete();   // empty: the line-off flow
            $table->unsignedSmallInteger('inbound_delay')->default(2);        // seconds it rings before the flow picks up
        });
        Schema::create('voice_inbound_ready', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');                 // 0: the test mode's example
            $table->foreignId('voice_flow_id')->nullable()->constrained('voice_flows')->nullOnDelete();
            $table->string('signature', 40);                                  // what it was made from; made again when that changes
            $table->text('package');
            $table->timestamp('built_at');
            $table->unique(['company_id', 'customer_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_inbound_ready');
        Schema::table('voice_reminder_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inbound_flow_id');
            $table->dropColumn(['inbound_on', 'inbound_delay']);
        });
    }
};
