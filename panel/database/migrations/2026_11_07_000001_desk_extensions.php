<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing phase 4a (plan 2.2 #11, সাময়িক সময় বাড়ানো): staff give an overdue customer a date until which the
 * line stays on although a bill is due, with a reason. The Desk's turn-off list (and later the real job) keeps them.
 *  - desk_extensions: every extension (who, when, until, why); one 'active' per customer, the latest is copied into
 *    desk_customers.extended_until. Ended by the hourly job when the date passes or a fresh copy shows nothing due.
 *  - billing_settings.extend_max_days / extend_per_month: the limits for members (the owner may go past them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desk_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_ref');                   // desk_customers.id
            $table->string('customer_code')->nullable();
            $table->date('until');                                       // the line stays on through this Bangladesh date
            $table->date('given_on');                                    // Bangladesh date it was given
            $table->unsignedSmallInteger('days')->default(0);            // until - given_on
            $table->text('reason');
            $table->string('kind', 10)->default('manual');               // manual | bulk | bot | import
            $table->string('source', 10)->default('panel');              // panel | app | engine | cli
            $table->string('status', 10)->default('active');             // active | ended | paid | canceled | replaced
            $table->boolean('over_limit')->default(false);               // the owner went past the limits
            $table->decimal('due_at_start', 12, 2)->nullable();
            $table->string('line_action', 20)->nullable();               // the line was off: enabled | enable_failed | left_off
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_note', 500)->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'status', 'until']);
            $table->index(['company_id', 'customer_ref', 'id']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('billing_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('extend_max_days')->default(10);
            $table->unsignedSmallInteger('extend_per_month')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_extensions');
        Schema::table('billing_settings', fn (Blueprint $table) => $table->dropColumn(['extend_max_days', 'extend_per_month']));
    }
};
