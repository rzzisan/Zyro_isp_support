<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The company's FAQ: question/answer pairs the bot answers from (added to its instructions). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('question', 500);
            $table->text('answer');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_faqs');
    }
};
