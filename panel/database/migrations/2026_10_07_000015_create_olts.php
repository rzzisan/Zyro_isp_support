<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's OLTs (read over SNMP by engine/olt_sync.py), their ONUs, and which customer router MAC sits behind
 * which ONU (from the OLT's MAC table). Nothing here writes to an OLT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand');                        // bdcom | vsol | ecom
            $table->string('host');
            $table->unsignedInteger('snmp_port')->default(161);
            $table->text('community');                      // encrypted with APP_KEY (read-only community)
            $table->string('router_identity')->nullable();  // the MikroTik its customers dial into (limits MAC lookups)
            $table->boolean('enabled')->default(true);
            $table->string('sys_name')->nullable();
            $table->string('sys_descr')->nullable();
            $table->jsonb('vlans')->nullable();
            $table->unsignedInteger('onu_total')->nullable();
            $table->unsignedInteger('onu_online')->nullable();
            $table->timestamp('last_poll_at')->nullable();
            $table->boolean('last_poll_ok')->nullable();
            $table->string('last_poll_message')->nullable();
            $table->timestamps();
        });

        Schema::create('onus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('if_index');
            $table->string('name');                         // EPON0/5:58
            $table->string('onu_mac')->nullable();
            $table->boolean('online')->default(false);
            $table->integer('status_code')->nullable();
            $table->integer('distance_m')->nullable();
            $table->decimal('rx_dbm', 5, 1)->nullable();
            $table->decimal('tx_dbm', 5, 1)->nullable();
            $table->decimal('temp_c', 5, 1)->nullable();
            $table->decimal('voltage', 5, 2)->nullable();
            $table->timestamp('last_change_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->unique(['olt_id', 'if_index']);
            $table->index(['company_id', 'online']);
        });

        Schema::create('customer_onus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('client_mac');                   // customer router MAC (MikroTik caller-id), upper case
            $table->foreignId('olt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('onu_id')->nullable()->constrained('onus')->nullOnDelete();
            $table->unsignedInteger('vlan')->nullable();
            $table->timestamp('checked_at');                // also kept for misses, so they are not asked every time
            $table->unique(['company_id', 'client_mac']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_onus');
        Schema::dropIfExists('onus');
        Schema::dropIfExists('olts');
    }
};
