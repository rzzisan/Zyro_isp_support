<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Field technicians in the app: a member is linked to their billing employee (whose name is on the tickets), and a
// field job is the work on one ticket: started / paused / finished, with a target time and the customer's line state.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('billing_employee_id', 20)->nullable();
        });
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('field_job_minutes')->default(90);
            $table->json('field_job_targets')->nullable();   // [{category_id, minutes, line_check}]
        });
        Schema::create('field_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('billing_tickets')->cascadeOnDelete();
            $table->string('status', 10)->default('running');   // running | paused | done
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->string('pause_reason', 300)->nullable();
            $table->unsignedSmallInteger('target_minutes');
            $table->boolean('line_check')->default(true);
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('line_online')->nullable();   // the last check while working, and at solve
            $table->timestamp('line_checked_at')->nullable();
            $table->string('force_reason', 500)->nullable();
            $table->timestamps();
            $table->unique('ticket_id');
            $table->index(['company_id', 'status']);
        });
        Schema::create('field_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12);   // start | pause | resume | solve | force
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_job_events');
        Schema::dropIfExists('field_jobs');
        Schema::table('bot_settings', fn (Blueprint $table) => $table->dropColumn(['field_job_minutes', 'field_job_targets']));
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('billing_employee_id'));
    }
};
