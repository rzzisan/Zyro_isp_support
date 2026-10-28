<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which AI key a company uses for which job (AiKey::JOBS); a job without a row uses the provider's first key. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_key_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('job', 30);
            $table->foreignId('ai_key_id')->constrained('ai_keys')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'job']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_key_jobs');
    }
};
