<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing's Expire Date (BillingLastDate, a day of the month) moved to the day the customer paid, when the line was
 * off for a while and a (nearly) full bill turned it back on (engine/expire_dates.py). kind bulk = the one-time list
 * the owner runs from the "মেয়াদ বদল" page; kind auto = the hourly timer after that, while expire_auto is on.
 * Every row keeps the day it replaced, so it can be put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expire_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->string('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('kind');                               // bulk | auto
            $table->string('status');                             // preview | skipped | queued | running | done | failed | rolled_back
            $table->string('rule');                               // pay_day | home_plus5 | home_cap20 | cap_max
            $table->unsignedTinyInteger('old_day')->nullable();
            $table->unsignedTinyInteger('new_day');
            $table->timestamp('off_at')->nullable();              // UTC, line disabled
            $table->timestamp('on_at')->nullable();               // UTC, line enabled again
            $table->unsignedInteger('off_hours')->nullable();
            $table->string('on_remark')->nullable();              // billing's enable remark (how it came back on)
            $table->date('paid_on');                              // Bangladesh date of the deciding receipt
            $table->decimal('paid', 12, 2)->default(0);           // paid + discount
            $table->decimal('payable', 12, 2)->default(0);
            $table->unsignedSmallInteger('paid_pct')->nullable();
            $table->string('method')->nullable();
            $table->boolean('is_online')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'kind', 'status']);
            $table->index(['company_id', 'customer_header_id']);
        });

        Schema::table('billing_connections', function (Blueprint $table) {
            $table->boolean('expire_auto')->default(false);             // hourly timer sets the day after each payment
            $table->unsignedSmallInteger('expire_min_off_hours')->default(24);
            $table->unsignedTinyInteger('expire_min_paid_pct')->default(85);
            $table->unsignedTinyInteger('expire_max_day')->default(28);
        });
    }

    public function down(): void
    {
        Schema::table('billing_connections', fn (Blueprint $table) => $table->dropColumn(
            ['expire_auto', 'expire_min_off_hours', 'expire_min_paid_pct', 'expire_max_day']));
        Schema::dropIfExists('expire_date_changes');
    }
};
