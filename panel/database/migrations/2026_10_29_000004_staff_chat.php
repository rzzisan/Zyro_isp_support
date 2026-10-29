<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * টিম চ্যাট: Desk users message each other, one to one or in a walkie-talkie group (App\Support\StaffChat). A chat is
 * a key, "g{group id}" or "u{lower user id}-{higher user id}"; each member's read position and mute per chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('chat', 40);
            $table->foreignId('walkie_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('media_type', 8)->nullable();     // image, voice
            $table->string('media_path')->nullable();        // under storage/app/staff-chat
            $table->string('media_mime', 64)->nullable();
            $table->unsignedSmallInteger('media_seconds')->nullable();
            $table->timestampTz('media_gone_at')->nullable(); // file deleted after the keep days (StaffChat::KEEP_DAYS)
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'chat', 'id']);
        });

        Schema::create('staff_chat_reads', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat', 40);
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->boolean('muted')->default(false);
            $table->primary(['company_id', 'user_id', 'chat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_chat_reads');
        Schema::dropIfExists('staff_chat_messages');
    }
};
