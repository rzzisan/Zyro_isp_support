<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the networks office phones may register from (JSON [{net, note}]; panel → অফিস ফোন → অনুমতির IP তালিকা)
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->text('office_allowed_ips')->nullable();
        });
        // what Zisan gave on 2026-11-06, plus the office LAN the phones actually come from
        DB::table('sip_settings')->update(['office_allowed_ips' => json_encode([
            ['net' => '103.157.253.0/24', 'note' => 'Office public'],
            ['net' => '103.153.185.0/24', 'note' => 'Office public'],
            ['net' => '10.90.90.0/24', 'note' => 'Office LAN'],
        ])]);
    }

    public function down(): void
    {
        Schema::table('sip_settings', function (Blueprint $table) {
            $table->dropColumn('office_allowed_ips');
        });
    }
};
