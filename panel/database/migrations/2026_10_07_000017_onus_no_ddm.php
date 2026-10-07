<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ONUs that don't report optical values (the OLT answers -65535 after ~10 s): skipped for a while. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onus', fn (Blueprint $table) => $table->timestamp('no_ddm_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('onus', fn (Blueprint $table) => $table->dropColumn('no_ddm_at'));
    }
};
