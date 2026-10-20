<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field teams of billing employees (e.g. Rifat + Maruf). Auto-assign gives a ticket to the whole team of the picked
 * person, and one ticket may never hold staff of two different teams (engine/ticket_autoassign.py checks every assign).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('members');                 // billing employee ids (billing_options kind 'employees')
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_teams');
    }
};
