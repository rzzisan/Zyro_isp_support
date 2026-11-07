<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 4b (plan 2.2 #3, #4): the Desk writes its own ledger.
 *  - desk_payments: daily_bill_id (billing's Daily Bill Collection row the same money went to; the nightly copy then
 *    skips billing's receipt of it, so it is never counted twice), collection_id (bill_collections), the cancel and
 *    transfer trail (a Desk row is never deleted: canceled/transferred + a reverse ledger row).
 *  - billing_settings.ledger_control (off | test | all) + ledger_test_codes: before the cutover only test accounts
 *    get Desk rows and corrections; 'all' only once the Desk is the main billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_bill_id')->nullable();
            $table->unsignedBigInteger('collection_id')->nullable();
            $table->unsignedBigInteger('transfer_of')->nullable();        // the payment (id) this one was moved from
            $table->unsignedBigInteger('transfer_to_ref')->nullable();    // desk_customers.id it was moved to
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('canceled_by')->nullable();
            $table->timestampTz('canceled_at')->nullable();
        });
        DB::statement('CREATE INDEX desk_payments_company_id_daily_bill_id_ix ON desk_payments (company_id, daily_bill_id)');

        Schema::table('billing_settings', function (Blueprint $table) {
            $table->string('ledger_control', 10)->default('off');
            $table->jsonb('ledger_test_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', fn (Blueprint $table) => $table->dropColumn(['ledger_control', 'ledger_test_codes']));
        DB::statement('DROP INDEX IF EXISTS desk_payments_company_id_daily_bill_id_ix');
        Schema::table('desk_payments', fn (Blueprint $table) => $table->dropColumn(['daily_bill_id', 'collection_id', 'transfer_of',
            'transfer_to_ref', 'cancel_reason', 'canceled_by', 'canceled_at']));
    }
};
