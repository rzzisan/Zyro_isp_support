<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('ispdigital');
            $table->string('base_url');
            $table->string('username');
            $table->text('password');                 // encrypted with APP_KEY
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->string('last_check_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ai_provider')->default('groq');
            $table->string('ai_model')->nullable();
            $table->string('bot_mode')->default('shadow'); // off | shadow | live
            $table->text('live_allowlist')->nullable();
            $table->boolean('auto_ticket')->default(true);
            $table->string('reply_signature')->nullable();
            $table->text('extra_prompt')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('label')->nullable();
            $table->text('api_key');                  // encrypted with APP_KEY
            $table->string('model')->nullable();
            $table->timestamp('rate_limited_until')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_keys');
        Schema::dropIfExists('bot_settings');
        Schema::dropIfExists('billing_connections');
    }
};
