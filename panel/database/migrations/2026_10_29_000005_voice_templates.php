<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice reminder, part 1: the messages a reminder call plays (App\Models\VoiceTemplate). Text read by Gemini TTS
 * (engine/voice_tts.py, the audio kept by the text's hash) or the company's own uploaded recording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('general');
            $table->string('name');
            $table->string('source', 16)->default('tts');      // tts | upload
            $table->text('body')->nullable();
            $table->string('voice', 32)->default('Kore');
            $table->decimal('speed', 3, 2)->default(1);
            $table->string('audio_path')->nullable();          // upload: private disk, voice-templates/{company}/...
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_templates');
    }
};
