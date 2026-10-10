<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing support page's lists (employees, departments, problem categories, priorities), kept in the desk so
 * forms don't ask billing each time. engine/ticket_sync.py refreshes them every hour; gone ones get active = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                 // employees | departments | categories | priorities
            $table->string('option_id');            // billing's id (AssignEmpHeadId, ...)
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_options');
    }
};
