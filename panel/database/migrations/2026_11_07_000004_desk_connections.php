<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 5 (নতুন সংযোগ, plan 2.4খ): a new connection made on the Desk is sent to billing
 * (POST /Customer/Add) while billing is the main system; billing makes the PPP secret, the Desk reads it back.
 *  - desk_connections: one row per send: the form without the password, billing's header id / client code,
 *    the router's secret as read back (profile, disabled), the result.
 *  - billing_settings.connection_control (off | test | all, default off) + connection_test_prefix (usernames
 *    allowed in 'test', e.g. "zyrotest").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->string('connection_control', 10)->default('off');
            $table->string('connection_test_prefix', 40)->nullable();
        });

        Schema::create('desk_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('sending');            // sending | done | check | unsure | failed
            $table->string('name')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('username', 60);                              // PPPoE ID (never the password)
            $table->string('server_name', 60)->nullable();
            $table->string('profile', 60)->nullable();
            $table->string('package_name')->nullable();
            $table->decimal('monthly_bill', 12, 2)->nullable();
            $table->string('status_name', 30)->nullable();
            $table->jsonb('form')->nullable();                           // the form as filled, password removed
            $table->unsignedBigInteger('header_id')->nullable();        // billing CustomerHeaderId
            $table->string('customer_code', 30)->nullable();             // billing Client Code
            $table->string('secret_profile', 60)->nullable();
            $table->boolean('secret_disabled')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 100)->nullable();
            $table->string('source', 10)->default('panel');              // panel | api | cli
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['company_id', 'created_at']);
        });

        // one live send per username: a double click can't make two billing customers
        DB::statement("CREATE UNIQUE INDEX desk_connections_one_username ON desk_connections (company_id, lower(username))
                       WHERE status IN ('sending', 'done', 'check', 'unsure')");
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_connections');
        Schema::table('billing_settings', fn (Blueprint $table) => $table->dropColumn(['connection_control', 'connection_test_prefix']));
    }
};
