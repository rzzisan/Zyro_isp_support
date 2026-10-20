<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which staff member worked tickets in which zone / sub zone / box, kept in the desk (engine/ticket_autoassign.py
 * fills it from every ticket sync and never deletes rows). A new ticket with no staff goes to the one who last
 * worked the customer's box, else sub zone, else zone; a pinned row wins in its area. bot_settings.auto_assign
 * switches the auto-assign off for a company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('zone');
            $table->string('subzone')->default('');
            $table->string('box')->default('');
            $table->string('employee_name');         // as billing writes it, without the "(10-07-26)" date
            $table->unsignedInteger('tickets')->default(0);
            $table->string('last_complain_id')->nullable();
            $table->timestamp('last_worked_at')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'zone', 'subzone', 'box', 'employee_name']);
            $table->index(['company_id', 'last_worked_at']);
        });
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('auto_assign')->default(true);
            $table->jsonb('auto_assign_exclude')->nullable();   // billing employee ids never auto-assigned
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_staff');
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_assign', 'auto_assign_exclude']);
        });
    }
};
