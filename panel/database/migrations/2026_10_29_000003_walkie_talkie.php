<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walkie-talkie in the Desk app (LiveKit): groups the owner fills with members, every push-to-talk transmission and
 * private call recorded by the engine (engine/walkie.py), the private calls themselves, and the daily on/off times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walkie_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('membership_walkie_group', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('company_user')->cascadeOnDelete();
            $table->foreignId('walkie_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['membership_id', 'walkie_group_id']);
        });

        Schema::create('walkie_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('callee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('ringing');   // ringing, talking, ended, declined, missed
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        // one row per transmission in a group (who spoke), or one per private call (both sides mixed)
        Schema::create('walkie_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('walkie_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('walkie_call_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('started_at');
            $table->decimal('seconds', 8, 1);
            $table->string('path');                         // under storage/app/walkie
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'walkie_group_id', 'started_at']);
        });

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->string('walkie_on_at', 5)->nullable();   // "10:00": the app turns the walkie-talkie on (push)
            $table->string('walkie_off_at', 5)->nullable();  // and off; null = stays on until the member turns it off
            $table->unsignedSmallInteger('walkie_keep_days')->nullable();  // recordings kept (null = 90)
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', fn (Blueprint $table) => $table->dropColumn(['walkie_on_at', 'walkie_off_at', 'walkie_keep_days']));
        Schema::dropIfExists('walkie_clips');
        Schema::dropIfExists('walkie_calls');
        Schema::dropIfExists('membership_walkie_group');
        Schema::dropIfExists('walkie_groups');
    }
};
