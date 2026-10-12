<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * billing_options also holds billing's Configuration lists (zones, sub zones, boxes, packages, ...), and no list is
 * refreshed on a timer any more: only the Sync buttons on the "বিলিং কনফিগারেশন" pages (engine/billing_config.py).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_options', function (Blueprint $table) {
            $table->string('short_value')->nullable()->after('name');
            $table->string('parent_id')->nullable()->after('short_value');      // sub zone -> zone, box -> sub zone
            $table->string('parent_name')->nullable()->after('parent_id');
            $table->string('zone_id')->nullable()->after('parent_name');        // box's zone
            $table->string('zone_name')->nullable()->after('zone_id');
            $table->boolean('billing_enabled')->nullable()->after('zone_name'); // billing's own on/off switch, where it has one
            $table->jsonb('extra')->nullable()->after('billing_enabled');      // package price / Mbps / VAS, details
            $table->index(['company_id', 'kind', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_options', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind', 'active']);
            $table->dropColumn(['short_value', 'parent_id', 'parent_name', 'zone_id', 'zone_name', 'billing_enabled', 'extra']);
        });
    }
};
