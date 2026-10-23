<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The engine's message pushes (app + browser) for field technicians: only the chats of their running work, which the
// panel keeps here whenever a job or a member changes (FieldWork::refreshChats).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->boolean('field_restricted')->default(false);
            $table->jsonb('field_chat_ids')->nullable();
        });
        // technicians already set up get no other chats' pushes from now on; their running work fills in on the next change
        foreach (\Illuminate\Support\Facades\DB::table('company_user')->where('role', 'agent')->get(['id', 'permissions']) as $m) {
            if (in_array('field_jobs', json_decode((string) $m->permissions, true) ?: [], true)) {
                \Illuminate\Support\Facades\DB::table('company_user')->where('id', $m->id)->update(['field_restricted' => true, 'field_chat_ids' => '[]']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn(['field_restricted', 'field_chat_ids']));
    }
};
