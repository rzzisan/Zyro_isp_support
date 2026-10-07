<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Support tickets copied from the company's billing software (engine/ticket_sync.py), read in the panel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('complain_id');                  // ticket number in the billing software
            $table->string('customer_id')->nullable();
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('username')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('mobile')->nullable();
            $table->string('zone')->nullable();
            $table->string('subzone')->nullable();
            $table->string('box')->nullable();
            $table->string('category')->nullable();
            $table->string('priority')->nullable();         // low | medium | high
            $table->string('state');                        // pending | processing | solved | closed
            $table->string('assigned_to')->nullable();
            $table->string('solved_by')->nullable();
            $table->string('created_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('opened_at')->nullable();     // UTC
            $table->timestamp('solved_at')->nullable();     // UTC
            $table->jsonb('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'complain_id']);
            $table->index(['company_id', 'state']);
            $table->index(['company_id', 'opened_at']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tickets');
    }
};
