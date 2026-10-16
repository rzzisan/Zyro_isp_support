<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONU stock, ONU handed to customers, new connections (+ due collection) and other stock items,
 * moved from the old zisan.me/onu app. A company module ("onu"), on only for the companies that have it.
 * legacy_id = the row's id in the old MySQL app, so the import can run again without duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->jsonb('modules')->nullable(); // optional features turned on for this company, e.g. ["onu"]
        });
        DB::table('companies')->where('slug', 'century-link-network')->update(['modules' => json_encode(['onu'])]);

        // old staff who only appear in old records get a technician row without a WhatsApp number
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('wa_number')->nullable()->change();
        });

        Schema::create('onu_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'legacy_id']);
        });

        Schema::create('onu_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('onu_brands')->restrictOnDelete();
            $table->integer('quantity');
            $table->date('purchase_date');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'legacy_id']);
        });

        Schema::create('new_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('connected_on');
            $table->string('customer_kind')->default('customer');   // customer | other (no customer ID)
            $table->string('customer_code')->nullable();             // billing's ID with leading zeros, e.g. 0976
            $table->string('legacy_customer_code')->nullable();      // what the old app had, when it was not an ID
            $table->foreignId('billing_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->text('address')->nullable();
            $table->string('connection_type');
            $table->jsonb('materials')->nullable();                  // ["অনু", "ফাইবার", "অনু-রাউটার"]
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('deposit', 12, 2)->default(0);           // includes later due payments, like the old app
            $table->decimal('due', 12, 2)->default(0);
            $table->foreignId('order_taker_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('money_with_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'connected_on']);
            $table->unique(['company_id', 'legacy_id']);
        });
        DB::statement('CREATE UNIQUE INDEX new_connections_company_customer_unique ON new_connections (company_id, customer_code) WHERE customer_code IS NOT NULL');

        Schema::create('connection_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('new_connections')->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->foreignId('collected_by_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'legacy_id']);
        });

        Schema::create('onu_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at');                         // UTC; shown in Dhaka time
            $table->string('customer_kind')->default('customer');
            $table->string('customer_code')->nullable();
            $table->string('legacy_customer_code')->nullable();
            $table->foreignId('billing_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->constrained('onu_brands')->restrictOnDelete();
            $table->string('mac_raw');                                // as typed
            $table->string('mac_norm')->nullable();                   // A28F06053740 (hex only, upper case)
            $table->string('purpose');
            $table->string('note')->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('new_connections')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who made the entry
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'assigned_at']);
            $table->index(['company_id', 'customer_code']);
            $table->index(['company_id', 'mac_norm']);
            $table->unique(['company_id', 'legacy_id']);
        });

        Schema::create('onu_assignment_technician', function (Blueprint $table) {
            $table->foreignId('onu_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->primary(['onu_assignment_id', 'technician_id']);
        });

        Schema::create('stock_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('maker')->nullable();
            $table->integer('current_stock')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'legacy_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('stock_products')->restrictOnDelete();
            $table->date('moved_on');
            $table->string('type');                                   // in | out
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2)->nullable();          // in only
            $table->decimal('total_price', 12, 2)->nullable();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete(); // out: who took it
            $table->string('reference')->nullable();                  // out: where it went (free text)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'moved_on']);
            $table->unique(['company_id', 'legacy_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'stock_products', 'onu_assignment_technician', 'onu_assignments', 'connection_payments',
            'new_connections', 'onu_stock_entries', 'onu_brands'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('modules'));
    }
};
