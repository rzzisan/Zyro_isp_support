<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments of the billing software (Daily Bill page: Webhook Payments and Paybill Payments), copied by
 * engine/online_payment_sync.py. Daily Bill Collection never lists them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source');                              // webhook | paybill
            $table->unsignedBigInteger('billing_row_id');          // billing's NotificationId (webhook) / InfoId (paybill)
            $table->string('gateway')->nullable();                 // bKash, Nagad ...
            $table->string('type_for')->nullable();                // BKASH_WEBHOOK, BKASH_PAYBILL ...
            $table->string('trx_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('payer')->nullable();                   // the paying wallet number
            $table->string('merchant')->nullable();                // our receiving number / short code
            $table->string('reference')->nullable();               // what the customer typed (customer code when right)
            $table->string('customer_code')->nullable();           // paybill's CustomerCode
            $table->string('match_code')->nullable();              // customer id without leading zeros, when the code/reference is one
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->unsignedSmallInteger('status_id')->nullable(); // 1 requested, 2 auto settled, 3 manually settled, 4 transferred, 8 retransferred
            $table->string('status')->nullable();
            $table->string('trx_status')->nullable();
            $table->string('trx_type')->nullable();
            $table->timestamp('paid_at');                          // UTC
            $table->timestamp('billing_created_at')->nullable();
            $table->timestamp('billing_updated_at')->nullable();
            $table->string('updated_by')->nullable();              // staff who settled/transferred it by hand
            $table->text('note')->nullable();
            $table->timestamp('alerted_at')->nullable();           // "payment requested" push sent (or skipped by a backfill)
            $table->timestamp('synced_at')->nullable();
            $table->unique(['company_id', 'source', 'billing_row_id']);
            $table->index(['company_id', 'paid_at']);
            $table->index(['company_id', 'trx_id']);
            $table->index(['company_id', 'customer_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_online_payments');
    }
};
