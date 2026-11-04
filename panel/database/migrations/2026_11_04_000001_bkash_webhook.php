<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bKash merchant webhook (IPN) straight into the Desk (engine/bkash_webhook.py): bKash allows one webhook per wallet,
 * so once the URL moves here from the billing software, the Desk records each payment and (switch) puts it into billing
 * as an approved bKash bill. Payments land in billing_online_payments with source 'desk_webhook'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bkash_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);              // off: messages are kept, nothing is confirmed or recorded
            $table->string('merchant_number')->nullable();           // our wallet (creditShortCode); empty = any
            $table->string('topic_arn')->nullable();                 // the confirmed bKash SNS topic; only it is accepted
            $table->string('pending_topic_arn')->nullable();         // a subscription request waiting for the owner
            $table->text('pending_subscribe_url')->nullable();
            $table->timestamp('pending_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('settle_in_billing')->default(false);    // post each matched payment to billing as an approved bKash bill
            $table->foreignId('settle_user_id')->nullable()->constrained('users')->nullOnDelete(); // whose billing login posts/approves
            $table->boolean('enable_line')->default(true);           // turn a disabled line on after the bill, if paid enough
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bkash_webhook_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('sns_message_id')->nullable();
            $table->string('type')->nullable();                      // SubscriptionConfirmation | Notification | UnsubscribeConfirmation
            $table->string('topic_arn')->nullable();
            $table->boolean('verified')->default(false);             // Amazon SNS signature checked
            $table->string('result');                                // recorded | duplicate | rejected | pending | confirmed | ignored | failed
            $table->text('error')->nullable();
            $table->json('body')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();    // billing_online_payments.id
            $table->timestamp('received_at');
            $table->unique(['company_id', 'sns_message_id']);
            $table->index(['company_id', 'received_at']);
        });

        Schema::table('billing_online_payments', function (Blueprint $table) {
            $table->string('matched_by')->nullable();                // code | mobile | staff (desk_webhook rows)
            $table->string('settle_state')->nullable();              // null | pending | settled | failed | off
            $table->text('settle_error')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('bill_collection_id')->nullable();
            $table->string('settled_by')->nullable();
        });
        // one row per bKash transaction among the Desk's own webhook payments (bKash may send a notification twice)
        DB::statement("CREATE UNIQUE INDEX billing_online_payments_desk_trx ON billing_online_payments (company_id, trx_id) WHERE source = 'desk_webhook'");
        // customer codes are at least 4 digits with leading zeros (0042): match_code now keeps them (engine code_of)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE billing_online_payments SET match_code = lpad(match_code, 4, '0') WHERE match_code IS NOT NULL AND length(match_code) < 4");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_online_payments_desk_trx');
        Schema::table('billing_online_payments', function (Blueprint $table) {
            $table->dropColumn(['matched_by', 'settle_state', 'settle_error', 'settled_at', 'bill_collection_id', 'settled_by']);
        });
        Schema::dropIfExists('bkash_webhook_messages');
        Schema::dropIfExists('bkash_webhooks');
    }
};
