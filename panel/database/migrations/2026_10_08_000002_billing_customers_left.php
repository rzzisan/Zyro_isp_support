<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Left customers (billing's Customer → Left list, /Customer/AjaxLeftCustomerList) in the same table as the rest:
 * same CustomerHeaderId space, and a customer moves between the two lists (Active → Left, Left → Active).
 * Everything that means "our current customers" filters is_left = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->boolean('is_left')->default(false);
            $table->date('left_on')->nullable();                // billing's LeftDate
            $table->index(['company_id', 'is_left']);
        });

        Schema::table('billing_customer_syncs', function (Blueprint $table) {
            $table->unsignedInteger('left_total')->default(0);
            $table->unsignedInteger('became_left')->default(0);
            $table->unsignedInteger('came_back')->default(0);
        });

        // every Active → Left and Left → Active move the sync sees
        Schema::create('billing_customer_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('header_id');
            $table->string('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('change');                           // left | returned
            $table->date('left_on')->nullable();
            $table->timestamp('seen_at')->useCurrent();
            $table->index(['company_id', 'header_id']);
            $table->index(['company_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_customer_changes');
        Schema::table('billing_customer_syncs', function (Blueprint $table) {
            $table->dropColumn(['left_total', 'became_left', 'came_back']);
        });
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_left']);
            $table->dropColumn(['is_left', 'left_on']);
        });
    }
};
