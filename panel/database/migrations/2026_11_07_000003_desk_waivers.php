<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 4c (plan 2.2 #2, বিল মাফ): a month in which the line stayed off the whole time and no money
 * came has its bill waived to 0. While billing is the master the Desk only lists whom it would waive (status
 * 'shadow'); once the Desk is the main billing the waive goes into the ledger (status 'applied').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desk_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedBigInteger('customer_ref');                   // desk_customers.id
            $table->unsignedBigInteger('bill_ref')->nullable();           // desk_bills.id
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 10)->default('shadow');              // shadow | applied | pending
            $table->string('off_since')->nullable();                      // when the line went off (billing's log), text as shown
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('applied_at')->nullable();
            $table->unique(['company_id', 'period_start', 'customer_ref']);
            $table->index(['company_id', 'period_start', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_waivers');
    }
};
