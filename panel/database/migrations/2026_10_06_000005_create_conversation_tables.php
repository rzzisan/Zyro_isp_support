<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Written by the Python engine, read by the panel (inbox). Everything is per company. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload');
            $table->timestamp('received_at')->useCurrent();
        });

        Schema::create('wa_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('wa_number');                    // customer's WhatsApp number (8801...)
            $table->string('name')->nullable();
            $table->string('customer_id')->nullable();      // billing customer the chat is about
            $table->jsonb('ident_state')->nullable();       // identification flow state
            $table->timestamp('bot_paused_until')->nullable();
            $table->boolean('bot_paused')->default(false);  // paused until a person resumes
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'wa_number']);
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('wa_contacts')->cascadeOnDelete();
            $table->foreignId('wa_account_id')->nullable()->constrained('wa_accounts')->nullOnDelete();
            $table->string('wa_message_id')->nullable()->unique();
            $table->string('direction');                    // in | out
            $table->string('sender');                       // customer | bot | staff | app | campaign
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();  // staff who replied
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->string('media_id')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('status')->nullable();           // sent | delivered | read | failed
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'contact_id', 'created_at']);
        });

        Schema::create('wa_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('wa_contacts')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('wa_messages')->nullOnDelete();
            $table->jsonb('context')->nullable();
            $table->text('draft')->nullable();
            $table->string('mode');                         // shadow | sent | dry_run | flow
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('error')->nullable();
            $table->text('ticket_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_drafts');
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_contacts');
        Schema::dropIfExists('wa_events');
    }
};
