<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI খরচ: one row per AI call the engine makes (tokens in/out, which key and model, what for),
 * each key's latest provider quota (rate-limit headers), and the company's price per model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_key_id')->nullable()->constrained('ai_keys')->nullOnDelete();
            $table->string('provider', 30);
            $table->string('model', 120);
            $table->string('purpose', 20); // customer | technician | voice
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0); // voice: audio seconds
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->boolean('ok')->default(true);
            $table->string('error', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('ai_keys', function (Blueprint $table) {
            $table->jsonb('quota')->nullable();
            $table->timestamp('quota_at')->nullable();
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('model', 120);
            $table->decimal('input_per_million', 10, 4)->default(0); // USD
            $table->decimal('output_per_million', 10, 4)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
        Schema::table('ai_keys', fn (Blueprint $table) => $table->dropColumn(['quota', 'quota_at']));
        Schema::dropIfExists('ai_usage');
    }
};
