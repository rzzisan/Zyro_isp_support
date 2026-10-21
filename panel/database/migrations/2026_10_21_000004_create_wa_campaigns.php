<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp template sends from the Desk ("WhatsApp পাঠান"): one wa_campaigns row per send, one wa_campaign_messages
 * row per number. The engine (engine/wa_campaign.py) sends them in the background with the company's own token;
 * Meta's status webhooks move each one to delivered / read / failed. Per-message rates on the number are only for
 * the cost estimate (Meta bills the company's own card).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->decimal('utility_rate', 8, 4)->nullable();      // taka per utility template message
            $table->decimal('marketing_rate', 8, 4)->nullable();    // taka per marketing template message
        });

        Schema::create('wa_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wa_account_id')->nullable()->constrained('wa_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');                                 // single | bulk
            $table->string('template_name');
            $table->string('template_language');
            $table->string('template_category')->nullable();        // UTILITY | MARKETING | AUTHENTICATION
            $table->text('template_body');                          // body as approved, {{1}} ... not filled
            $table->jsonb('params')->nullable();                    // [{field: name} | {text: "..."}] per {{n}}
            $table->jsonb('filters')->nullable();                   // bulk: the filters used
            $table->integer('total')->default(0);
            $table->integer('sent')->default(0);                    // Meta took it (sent, delivered or read)
            $table->integer('delivered')->default(0);
            $table->integer('read')->default(0);
            $table->integer('failed')->default(0);
            $table->decimal('cost', 12, 2)->nullable();             // estimate at the time of sending
            // queued | sending | paused (account/template error, see error) | done | cancelled
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('wa_campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('wa_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('wa_number');                            // 8801XXXXXXXXX
            $table->jsonb('params')->nullable();                    // the filled {{n}} values, in order
            $table->text('text');                                   // what the customer sees
            // queued -> sending -> sent -> delivered -> read | failed; cancelled; unknown = cut off while sending, not resent
            $table->string('status')->default('queued');
            $table->string('wa_message_id')->nullable()->unique();
            $table->foreignId('chat_message_id')->nullable()->constrained('wa_messages')->nullOnDelete();  // copy in the inbox
            $table->string('error_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index(['company_id', 'wa_number']);
            $table->index(['company_id', 'customer_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_campaign_messages');
        Schema::dropIfExists('wa_campaigns');
        Schema::table('wa_accounts', fn (Blueprint $table) => $table->dropColumn(['utility_rate', 'marketing_rate']));
    }
};
