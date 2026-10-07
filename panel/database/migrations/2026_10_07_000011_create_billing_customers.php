<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's customers copied from the billing software (engine/customer_sync.py, nightly).
 * Stable data lives here; live data (PPPoE/ONU state, today's payments) is still asked from billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('header_id');            // CustomerHeaderId
            $table->string('customer_id')->nullable();          // "0976", leading zeros kept
            $table->string('username')->nullable();             // PPPoE ID
            $table->text('pppoe_password')->nullable();         // encrypted with APP_KEY; owner/admin only
            $table->string('name')->nullable();
            $table->string('mobile')->nullable();
            $table->string('mobile_normalized')->nullable();    // 8801XXXXXXXXX (WhatsApp form)
            $table->string('email')->nullable();
            $table->string('nid')->nullable();
            $table->string('address')->nullable();
            $table->string('house')->nullable();
            $table->string('road')->nullable();
            $table->string('thana')->nullable();
            $table->string('district')->nullable();
            $table->string('zone')->nullable();
            $table->string('subzone')->nullable();
            $table->string('box')->nullable();
            $table->string('package')->nullable();
            $table->unsignedInteger('package_id')->nullable();
            $table->string('speed')->nullable();
            $table->decimal('monthly_bill', 10, 2)->nullable();
            $table->string('connection_type')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('protocol')->nullable();
            $table->string('server')->nullable();
            $table->string('status')->nullable();
            $table->boolean('disabled')->default(false);
            $table->boolean('is_vip')->default(false);
            $table->unsignedSmallInteger('bill_day')->nullable();
            $table->decimal('payable', 10, 2)->nullable();
            $table->decimal('paid', 10, 2)->nullable();
            $table->decimal('due', 10, 2)->nullable();
            $table->decimal('advance', 10, 2)->nullable();
            $table->date('last_payment_date')->nullable();
            $table->string('assigned_employee')->nullable();
            $table->date('joined_on')->nullable();
            $table->date('registered_on')->nullable();
            $table->string('device')->nullable();
            $table->string('device_mac')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->string('fiber_code')->nullable();
            $table->jsonb('extra')->nullable();                 // the billing row (no passwords), same shape the bot uses
            $table->timestamp('details_fetched_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('gone_at')->nullable();           // not in billing any more (kept, not deleted)
            $table->timestamps();
            $table->unique(['company_id', 'header_id']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'username']);
            $table->index(['company_id', 'mobile_normalized']);
            $table->index(['company_id', 'zone', 'subzone']);
        });

        Schema::create('billing_customer_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('gone')->default(0);
            $table->text('error')->nullable();
        });

        Schema::create('customer_password_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_id')->nullable();
            $table->timestamp('viewed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_views');
        Schema::dropIfExists('billing_customer_syncs');
        Schema::dropIfExists('billing_customers');
    }
};
