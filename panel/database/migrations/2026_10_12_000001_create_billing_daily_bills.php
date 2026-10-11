<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every bill collected in the billing software (Daily Bill Collection), copied by engine/daily_bill_sync.py,
 * and which collectors' bills an agent may see on the বিল কালেকশন page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_daily_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('daily_bill_id');           // billing's DailyBillId
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('username')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('mobile')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('emp_header_id')->nullable();  // billing employee who received the money
            $table->string('received_by')->nullable();
            $table->string('created_by')->nullable();              // billing username that entered it
            $table->timestamp('collected_at');                     // UTC
            $table->boolean('is_approved')->default(false);
            $table->string('approved_by')->nullable();
            $table->timestamp('billing_updated_at')->nullable();   // approval/deletion time when approved/deleted
            $table->decimal('monthly_bill', 12, 2)->default(0);
            $table->decimal('receive_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->unique(['company_id', 'daily_bill_id']);
            $table->index(['company_id', 'collected_at']);
            $table->index(['company_id', 'emp_header_id']);
        });
        Schema::table('company_user', function (Blueprint $table) {
            $table->jsonb('bill_view_employees')->nullable(); // agent: billing employee ids whose collections they see
        });
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('bill_view_employees'));
        Schema::dropIfExists('billing_daily_bills');
    }
};
