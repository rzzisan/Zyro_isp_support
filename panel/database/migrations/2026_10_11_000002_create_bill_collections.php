<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bills collected from the Desk into the billing software (written by the engine), including refused attempts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->string('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('username')->nullable();
            $table->string('bill_period_id')->nullable();
            $table->decimal('payable', 12, 2)->nullable();   // due in billing just before
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('received_by')->nullable();
            $table->string('bill_month')->nullable();
            $table->string('trx_no')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('send_sms')->default(true);
            $table->boolean('next_billing_date')->default(false);
            $table->string('result');                        // saved | blocked | failed
            $table->text('message')->nullable();
            $table->unsignedBigInteger('daily_bill_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'customer_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_collections');
    }
};
