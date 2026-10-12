<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything billing's customer details page shows, tab by tab (engine/customer_history.py, read-only on billing).
 * No PPPoE password is ever stored here: the change and enable/disable logs send it on every row and the engine
 * drops it while parsing (only "it changed" is kept, as a boolean). See docs/customer-profile-plan.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Service / Network / Personal tabs (details page HTML); the nightly customer sync leaves these alone
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->string('mikrotik_profile')->nullable();
            $table->date('billing_start_month')->nullable();
            $table->string('reference_by')->nullable();
            $table->string('setup_by')->nullable();
            $table->timestamp('last_login_at')->nullable();      // UTC
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('occupation')->nullable();
            $table->string('phone')->nullable();
            $table->string('upazila')->nullable();
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('form_no')->nullable();
            $table->jsonb('details')->nullable();                 // facebook, linkedin, cable, core, vendor, installation ...
        });

        Schema::create('billing_customer_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->unsignedBigInteger('bill_master_id');
            $table->unsignedInteger('bill_period_id')->nullable();
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('month_name')->nullable();             // "Oct-26"
            $table->date('created_on')->nullable();
            $table->string('package')->nullable();
            $table->string('speed')->nullable();
            $table->decimal('bill_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('due', 12, 2)->default(0);
            $table->string('status')->nullable();
            $table->string('processed_by')->nullable();
            $table->text('remark')->nullable();
            $table->boolean('is_editable')->default(false);
            $table->jsonb('versions')->nullable();                // billing's "childs": every edit of this bill
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();          // no longer listed by billing (kept)
            $table->unique(['company_id', 'bill_master_id']);
            $table->index(['company_id', 'customer_header_id']);
        });

        Schema::create('billing_customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->unsignedBigInteger('bill_header_id');
            $table->date('received_on')->nullable();
            $table->date('entered_on')->nullable();
            $table->timestamp('approved_at')->nullable();        // UTC
            $table->string('bill_month')->nullable();
            $table->string('package')->nullable();
            $table->decimal('payable', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);
            $table->string('method')->nullable();
            $table->string('method_short')->nullable();
            $table->boolean('is_online')->default(false);         // billing's IsRealPayment (payment gateway)
            $table->boolean('is_canceled')->default(false);
            $table->string('received_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->text('remarks')->nullable();
            $table->string('trx_no')->nullable();
            $table->string('money_receipt_no')->nullable();
            $table->unsignedBigInteger('daily_bill_id')->nullable(); // the matching billing_daily_bills row, when known
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unique(['company_id', 'bill_header_id']);
            $table->index(['company_id', 'customer_header_id']);
        });

        Schema::create('billing_customer_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->string('remarks_type')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();          // UTC
            $table->string('created_by')->nullable();
            $table->string('row_hash', 40);
            $table->unique(['company_id', 'customer_header_id', 'row_hash']);
        });

        Schema::create('billing_customer_sms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->unsignedBigInteger('sms_log_id');
            $table->string('sms_type')->nullable();
            $table->string('type_name')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('sent_at')->nullable();             // UTC; only the day is known for older rows
            $table->string('status')->nullable();
            $table->string('sent_by')->nullable();
            $table->unique(['company_id', 'sms_log_id']);
            $table->index(['company_id', 'customer_header_id', 'sent_at']);
        });

        Schema::create('billing_customer_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->timestamp('changed_at')->nullable();          // UTC
            $table->string('changed_by')->nullable();
            $table->text('remarks')->nullable();
            $table->jsonb('snapshot');                            // every field after the change, never the password
            $table->jsonb('changed')->nullable();                 // {field: [before, after]} against the previous row
            $table->boolean('password_changed')->default(false);
            $table->string('row_hash', 40);
            $table->unique(['company_id', 'customer_header_id', 'row_hash']);
            $table->index(['company_id', 'customer_header_id', 'changed_at']);
        });

        Schema::create('billing_customer_line_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->timestamp('changed_at')->nullable();          // UTC
            $table->string('changed_by')->nullable();
            $table->string('action')->nullable();                 // enable | disable
            $table->text('remarks')->nullable();
            $table->string('row_hash', 40);
            $table->unique(['company_id', 'customer_header_id', 'row_hash']);
            $table->index(['company_id', 'customer_header_id', 'changed_at']);
        });

        Schema::create('billing_customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->unsignedBigInteger('invoice_id');
            $table->string('invoice_no')->nullable();
            $table->string('invoice_type')->nullable();           // ServiceInvoice | ProductInvoice
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('due', 12, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->text('special_note')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unique(['company_id', 'invoice_id']);
            $table->index(['company_id', 'customer_header_id']);
        });

        // when each part of a customer was last read from billing
        Schema::create('billing_customer_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_header_id');
            $table->string('section');
            $table->timestamp('fetched_at')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->text('error')->nullable();
            $table->unique(['company_id', 'customer_header_id', 'section']);
        });

        Schema::table('billing_tickets', function (Blueprint $table) {
            $table->string('source')->nullable();                 // null = ticket sync, 'history' = customer's complain history
        });
    }

    public function down(): void
    {
        Schema::table('billing_tickets', fn (Blueprint $table) => $table->dropColumn('source'));
        foreach (['fetches', 'invoices', 'line_logs', 'change_logs', 'sms', 'remarks', 'payments', 'bills'] as $t) {
            Schema::dropIfExists("billing_customer_{$t}");
        }
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->dropColumn(['mikrotik_profile', 'billing_start_month', 'reference_by', 'setup_by', 'last_login_at',
                'gender', 'birth_date', 'father_name', 'mother_name', 'occupation', 'phone', 'upazila',
                'present_address', 'permanent_address', 'form_no', 'details']);
        });
    }
};
