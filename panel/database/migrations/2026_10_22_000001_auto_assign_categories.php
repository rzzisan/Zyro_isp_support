<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-assign per ticket category: only the categories in auto_assign_area_categories go to the area's last staff;
 * auto_assign_category_staff ([{category_id, employees}]) gives other categories fixed staff; any other category is
 * left for a person to assign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->jsonb('auto_assign_area_categories')->nullable();
            $table->jsonb('auto_assign_category_staff')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_assign_area_categories', 'auto_assign_category_staff']);
        });
    }
};
