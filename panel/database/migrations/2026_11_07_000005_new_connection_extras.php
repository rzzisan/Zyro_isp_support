<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing phase 5 form extras (plan 2.4খ):
 *  - billing_settings.username_prefixes: [{zone_id, prefix}] — the PPPoE username the new connection form suggests
 *    for a zone starts with that prefix (e.g. "bp.").
 *  - sms_settings.welcome_sms / welcome_template_id: the welcome SMS after a new connection (never with the password).
 *  - desk_customer_changes: package change, Left / back, password, secret removal made on the Desk (engine/desk_change.py),
 *    written to billing's edit form and the router; one row per change, never the password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->json('username_prefixes')->nullable();
        });
        Schema::create('desk_customer_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                                  // package | left | back | password | remove
            $table->unsignedBigInteger('header_id');
            $table->string('customer_code', 30)->nullable();
            $table->string('username', 60)->nullable();
            $table->string('name')->nullable();
            $table->jsonb('detail')->nullable();                         // the request without the password
            $table->jsonb('before')->nullable();                         // billing's form values before (no password)
            $table->string('status', 10)->default('sending');            // sending | done | check | failed
            $table->string('error', 500)->nullable();
            $table->string('note', 300)->nullable();
            $table->string('secret_profile', 80)->nullable();
            $table->boolean('secret_disabled')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 100)->nullable();
            $table->string('source', 20)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['company_id', 'id']);
        });
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('welcome_sms')->default(false);
            $table->foreignId('welcome_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_customer_changes');
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('welcome_template_id');
            $table->dropColumn('welcome_sms');
        });
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->dropColumn('username_prefixes');
        });
    }
};
