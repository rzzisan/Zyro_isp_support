<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which panel menus each member may open (null = the defaults of their role). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', fn (Blueprint $table) => $table->jsonb('permissions')->nullable());
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('permissions'));
    }
};
