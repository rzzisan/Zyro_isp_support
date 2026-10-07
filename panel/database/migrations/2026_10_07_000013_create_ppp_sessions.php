<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Online PPPoE sessions copied from the MikroTik routers every 2 minutes (engine/ppp_sync.py). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->string('username');
            $table->string('address')->nullable();
            $table->string('caller_id')->nullable();
            $table->string('uptime')->nullable();
            $table->timestamp('seen_at');
            $table->unique(['company_id', 'username']);
            $table->index(['router_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppp_sessions');
    }
};
