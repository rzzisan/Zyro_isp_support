<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A technician can be linked to their billing employee: a ticket assigned to that employee in billing
 * (from the desk or the billing software) is sent to the technician on WhatsApp. ticket_notifications
 * records each send, one per ticket and technician.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('billing_employee_id')->nullable();   // AssignEmpHeadId in billing
            $table->string('billing_employee_name')->nullable();
            $table->boolean('notify_tickets')->default(true);
        });
        Schema::create('ticket_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('complain_id');
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_id')->nullable();
            $table->string('wa_number');
            $table->string('channel')->nullable();                // text (inside 24 h) | template
            $table->string('status')->default('pending');          // pending | sent | failed | skipped
            $table->text('body')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'complain_id', 'technician_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notifications');
        Schema::table('technicians', fn (Blueprint $table) => $table->dropColumn(['billing_employee_id', 'billing_employee_name', 'notify_tickets']));
    }
};
