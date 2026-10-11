<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-chat switch: messages from a muted number make no bell, sound or Web Push for anyone in the company. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->boolean('notify_muted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('wa_contacts', fn (Blueprint $table) => $table->dropColumn('notify_muted'));
    }
};
