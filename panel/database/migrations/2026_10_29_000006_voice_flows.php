<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voice reminder, part 2: call flows (steps, keys and what each key does), the reminder calls themselves (queue and
 * result; the voice bot's engine/voice_ivr.py places and runs them) and the numbers that asked for no more calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        // step 1's two reconnect results said {reconnect_until}/{reconnect_reason}; the call now says the bot's own
        // reconnect message ({reconnect_message}) instead, for templates nobody changed
        DB::table('voice_templates')->where('name', 'লাইন চালু হয়েছে')
            ->where('body', 'আপনার লাইন চালু হয়েছে। অনুগ্রহ করে {reconnect_until} তারিখের মধ্যে বিল পরিশোধ করুন।')->update(['body' => '{reconnect_message}']);
        DB::table('voice_templates')->where('name', 'লাইন চালু করা গেল না')
            ->where('body', 'দুঃখিত, এখন নিজে থেকে লাইন চালু করা যাচ্ছে না। {reconnect_reason}')->update(['body' => 'দুঃখিত। {reconnect_message}']);

        Schema::create('voice_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 16)->default('general');   // reminder | line_off | general
            $table->text('steps');                             // JSON, see App\Models\VoiceFlow
            $table->text('hold')->nullable();                  // JSON: what the customer hears while staff are rung
            $table->foreignId('goodbye_template_id')->nullable()->constrained('voice_templates')->nullOnDelete();
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'kind']);
        });

        Schema::create('voice_reminder_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voice_flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // who asked for a test call
            $table->string('kind', 16)->default('test');      // test | reminder | line_off
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('number', 20);                     // 8801XXXXXXXXX
            $table->text('package');                          // JSON: flow, audio files, SMS texts, customer (built by the panel)
            $table->string('status', 16)->default('queued');  // queued | dialing | answered | done | no_answer | busy | failed
            $table->string('dial_status', 20)->nullable();
            $table->string('result', 40)->nullable();          // sms | reconnected | reconnect_failed | staff | voicemail | callback | optout | ticket | listened | hung_up
            $table->text('keys')->nullable();                  // the keys pressed, in order
            $table->unsignedInteger('seconds')->nullable();
            $table->foreignId('call_log_id')->nullable()->constrained('call_logs')->nullOnDelete();
            $table->string('error', 300)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('voice_optouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('source', 20)->default('call');    // call (pressed the key) | panel
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_optouts');
        Schema::dropIfExists('voice_reminder_calls');
        Schema::dropIfExists('voice_flows');
    }
};
