<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 1 (ছায়া হিসাব): the Desk works the billing out itself and compares it with billing, while
 * billing stays the master (billing_mode ispdigital). Nothing here goes into the ledger or to billing.
 *  - desk_customer_balances gets the comparison of every nightly copy: the difference, a reason and since when.
 *  - desk_shadow_bills: the Desk's own monthly bills (made on the 1st from the customers as they are that morning),
 *    next to billing's bill for the same month, compared every night as billing's bills arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_customer_balances', function (Blueprint $table) {
            $table->decimal('diff', 12, 2)->nullable();               // Desk balance - billing due (null = matches)
            $table->string('mismatch_reason', 30)->nullable();        // recent_receipt | advance | not_active | left | other
            $table->date('mismatch_since')->nullable();               // first nightly copy that didn't match (Bangladesh date)
            $table->decimal('recent_receipts', 12, 2)->nullable();    // billing receipts of the last 3 days not in the copy yet
        });

        Schema::create('desk_shadow_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedBigInteger('customer_ref');                // desk_customers.id
            $table->unsignedBigInteger('run_id')->nullable();          // desk_billing_runs (kind generate)
            // what the Desk made, from the customer as it was when the bills were made
            $table->decimal('desk_amount', 12, 2)->nullable();         // null = the Desk didn't know this customer then
            $table->string('desk_reason', 30)->nullable();             // billed | not_active | left | joined_this_month | zero_bill | not_in_desk
            $table->string('customer_status')->nullable();
            $table->string('package_name')->nullable();
            $table->decimal('monthly_bill', 12, 2)->nullable();
            // billing's bill for the same month, at the last compare
            $table->decimal('billing_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('billing_ext_id')->nullable();  // BillMasterId
            $table->string('billing_package')->nullable();
            $table->string('result', 20)->default('pending');          // match | amount_diff | desk_only | billing_only | pending
            $table->string('hint', 30)->nullable();                    // package_changed | price_changed | status_changed | edited_in_billing
            $table->timestampTz('compared_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'period_start', 'customer_ref']);
            $table->index(['company_id', 'period_start', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_shadow_bills');
        Schema::table('desk_customer_balances', fn (Blueprint $table) => $table->dropColumn(['diff', 'mismatch_reason', 'mismatch_since', 'recent_receipts']));
    }
};
