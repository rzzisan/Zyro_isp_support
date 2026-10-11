<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Billing's MoneyReceiptNo of a synced bill, found after approval for its printed receipt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_daily_bills', function (Blueprint $table) {
            $table->string('money_receipt_no')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_daily_bills', fn (Blueprint $table) => $table->dropColumn('money_receipt_no'));
    }
};
