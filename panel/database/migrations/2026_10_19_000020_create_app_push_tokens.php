<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone push (Firebase Cloud Messaging) for the Android app: one FCM token per signed-in phone. It hangs off the
 * phone's Sanctum token, so signing out (or revoking the phone) drops its push too. The engine sends to these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_push_tokens');
    }
};
