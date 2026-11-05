<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 2 (বন্ধের তালিকা ছায়া): every day at the company's disable hour the Desk lists whose line it
 * would turn off for an unpaid bill (plan 2.2 #6, #10, #11), and once billing's enable/disable logs are copied it
 * compares the list with who billing (its auto job or staff) actually turned off. Nothing is turned off by the Desk.
 *  - desk_disable_shadow: one row per company, Bangladesh date and customer: the Desk's decision (disable / keep with
 *    a reason / none = only billing turned it off) next to what billing did.
 *  - billing_settings.disable_min_due: a due up to this much doesn't turn a line off (plan 2.2 #6, default 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->decimal('disable_min_due', 12, 2)->default(0);
        });

        Schema::create('desk_disable_shadow', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('day');                                        // Bangladesh date of the list
            $table->unsignedBigInteger('customer_ref');                 // desk_customers.id
            $table->unsignedBigInteger('run_id')->nullable();           // desk_billing_runs (kind disable_shadow)
            // the Desk's decision when the list was made
            $table->string('decision', 16);                             // disable | keep | none (only billing turned it off)
            $table->string('reason', 30)->nullable();                   // due_today | overdue | extended | recently_enabled | small_remainder ...
            $table->decimal('due', 12, 2)->nullable();                  // what was past its deadline then
            $table->decimal('balance', 12, 2)->nullable();              // the whole due then (+ advance -)
            $table->string('balance_from', 10)->nullable();             // desk (ledger) | billing (no history copied yet)
            $table->decimal('monthly_bill', 12, 2)->nullable();
            $table->date('expire_on')->nullable();                      // the Expire Date whose deadline passed
            $table->date('deadline')->nullable();                       // expire_on + the setting's days
            $table->date('extended_until')->nullable();
            $table->timestampTz('made_at')->nullable();
            // what billing did, at the last compare
            $table->timestampTz('billing_disabled_at')->nullable();
            $table->string('billing_disabled_by')->nullable();
            $table->string('result', 20)->default('pending');           // match | desk_only | billing_only | pending
            $table->string('hint', 30)->nullable();                     // paid_later | billing_next_day | not_due | not_active ...
            $table->timestampTz('compared_at')->nullable();
            $table->unique(['company_id', 'day', 'customer_ref']);
            $table->index(['company_id', 'day', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_disable_shadow');
        Schema::table('billing_settings', fn (Blueprint $table) => $table->dropColumn('disable_min_due'));
    }
};
