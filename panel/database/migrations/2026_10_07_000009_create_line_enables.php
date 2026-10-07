<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every line a technician asked the bot to turn on (enable in the billing software), and what happened. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_enables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->string('technician_name');
            $table->string('technician_number')->nullable();
            $table->string('customer_id')->nullable();
            $table->unsignedBigInteger('customer_header_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('username')->nullable();
            $table->string('due')->nullable();               // due amount when it was turned on
            $table->text('request')->nullable();             // the technician's message
            $table->string('result');                        // enabled | already_active | failed | dry_run
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_enables');
    }
};
