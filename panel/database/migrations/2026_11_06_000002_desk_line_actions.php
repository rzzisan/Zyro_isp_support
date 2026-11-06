<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 3 (MikroTik সরাসরি): the Desk turns a line off/on on the MikroTik itself and reads it back
 * (plan 2.2 #8, engine/desk_line.py). Before the cutover only test/pilot accounts.
 *  - desk_line_actions: one row per off/on: who, why, which router, the profile before/after, sessions removed, tries.
 *  - billing_settings.line_control (off | test | all) + line_test_codes (customer IDs / PPPoE usernames for 'test').
 *  - mikrotik_routers.block_profile: the profile a turned-off line gets (empty = the one named like BLOCKED-PROFILE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->string('line_control', 10)->default('off');
            $table->jsonb('line_test_codes')->nullable();
        });

        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('block_profile')->nullable();
        });

        Schema::create('desk_line_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_ref')->nullable();      // desk_customers.id
            $table->string('customer_code')->nullable();
            $table->string('username')->nullable();                      // PPPoE ID (never the password)
            $table->string('action', 12);                                // disable | enable | deactivate | activate
            $table->string('reason', 12)->default('manual');             // manual | test | unpaid | paid | partial | bot | extend | status
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 100)->nullable();
            $table->string('source', 10)->default('panel');              // panel | api | engine | cli
            $table->string('status', 10)->default('running');            // running | done | failed
            $table->unsignedBigInteger('router_id')->nullable();
            $table->string('router_name')->nullable();
            $table->string('profile_before')->nullable();
            $table->boolean('disabled_before')->nullable();
            $table->string('session_before')->nullable();                // uptime of the session that was up
            $table->string('profile_target')->nullable();
            $table->string('profile_after')->nullable();
            $table->boolean('disabled_after')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('sessions_killed')->default(0);
            $table->string('session_after')->nullable();                 // uptime of a new session seen at the check
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'customer_ref', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_line_actions');
        Schema::table('mikrotik_routers', fn (Blueprint $table) => $table->dropColumn('block_profile'));
        Schema::table('billing_settings', fn (Blueprint $table) => $table->dropColumn(['line_control', 'line_test_codes']));
    }
};
