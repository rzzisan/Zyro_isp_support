<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission groups of the team page: a group holds a list of menus (App\Support\Menu keys) and every member in it
 * gets them, on top of the member's own list (App\Support\Menu::keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('permissions');             // Menu keys
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('membership_permission_group', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('company_user')->cascadeOnDelete();
            $table->foreignId('permission_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['membership_id', 'permission_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_permission_group');
        Schema::dropIfExists('permission_groups');
    }
};
