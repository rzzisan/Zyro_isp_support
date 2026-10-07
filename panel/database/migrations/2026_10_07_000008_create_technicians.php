<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Field technicians' WhatsApp numbers: the bot treats them as staff, not customers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('wa_number');                    // 8801XXXXXXXXX, as WhatsApp sends it
            $table->boolean('active')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'wa_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technicians');
    }
};
