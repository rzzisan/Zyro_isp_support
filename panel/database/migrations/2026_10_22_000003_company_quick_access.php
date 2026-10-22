<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sidebar "কুইক অ্যাক্সেস" menus the owner picked (class names in order); null = App\Support\QuickAccess::DEFAULT.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->jsonb('quick_access')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('quick_access');
        });
    }
};
