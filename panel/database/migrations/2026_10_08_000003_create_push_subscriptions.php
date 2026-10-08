<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser push (Web Push) for new WhatsApp messages, so alerts arrive with the panel tab closed.
 * The panel stores each browser's subscription; the engine sends the push the moment a message comes in.
 * web_push_keys: the VAPID key pair, made by the engine on start (private key encrypted with APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('p256dh');
            $table->string('auth');
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'endpoint']);
        });

        Schema::create('web_push_keys', function (Blueprint $table) {
            $table->id();
            $table->text('public_key');
            $table->text('private_key');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_keys');
        Schema::dropIfExists('push_subscriptions');
    }
};
