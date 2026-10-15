<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desk's own SMS service (docs/billing-sms-service.md): the company's SMS gateway (Khudebarta), templates, every send
 * (one row per action) and every SMS (the log). The engine sends the queued SMS in the background (engine/sms.py).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('khudebarta');
            $table->string('endpoint_url')->nullable();           // send: form POST apikey, secretkey, callerID, toUser, messageContent
            // balance: the Khudebarta web portal's API (Bearer idToken from the portal login), docs/billing-sms-service.md
            $table->string('balance_url')->nullable();            // {provider_id} is filled in
            $table->string('provider_id')->nullable();            // portal providerID (= userID of the login); blank = from the login
            $table->string('portal_login_url')->nullable();
            $table->string('portal_username')->nullable();
            $table->text('portal_password')->nullable();          // encrypted
            $table->text('portal_token')->nullable();             // encrypted, written by the engine only
            $table->text('portal_refresh_token')->nullable();     // encrypted
            $table->timestamp('portal_token_expires_at')->nullable();
            $table->timestamp('portal_login_failed_at')->nullable();  // no new login for 10 minutes after a failure
            $table->string('status_url')->nullable();             // optional delivery report (messageid)
            $table->text('api_key')->nullable();                 // encrypted
            $table->text('secret_key')->nullable();              // encrypted
            $table->string('caller_id')->nullable();             // sender ID / masking
            $table->boolean('verify_tls')->default(true);
            $table->boolean('enabled')->default(false);           // off = SMS wait in the queue, nothing goes out
            $table->decimal('rate', 8, 4)->nullable();            // taka per SMS part, for the cost estimate
            $table->decimal('balance', 12, 2)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('body');
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');                               // single | bulk
            $table->text('body');                                 // as written, placeholders not filled
            $table->jsonb('filters')->nullable();                 // bulk: the filters used
            $table->integer('total')->default(0);
            $table->integer('sent')->default(0);
            $table->integer('failed')->default(0);
            $table->integer('parts')->default(0);                 // SMS parts of all messages
            $table->decimal('cost', 12, 2)->nullable();           // estimate at the time of sending
            $table->string('status')->default('queued');          // queued | sending | done | cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('sms_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('mobile');                             // 8801XXXXXXXXX
            $table->text('text');                                 // placeholders filled
            $table->smallInteger('parts')->default(1);
            $table->boolean('unicode')->default(false);
            // queued -> sending -> sent -> delivered | failed; cancelled; unknown = sending was cut off, not resent
            $table->string('status')->default('queued');
            $table->string('gateway_status')->nullable();
            $table->string('gateway_text')->nullable();
            $table->string('message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index(['company_id', 'customer_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_campaigns');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('sms_settings');
    }
};
