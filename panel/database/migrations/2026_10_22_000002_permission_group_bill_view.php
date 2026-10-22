<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whose collected bills the members of a group see on the bill collection page (billing employee ids, like company_user.bill_view_employees). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permission_groups', fn (Blueprint $table) => $table->jsonb('bill_view_employees')->nullable());
    }

    public function down(): void
    {
        Schema::table('permission_groups', fn (Blueprint $table) => $table->dropColumn('bill_view_employees'));
    }
};
