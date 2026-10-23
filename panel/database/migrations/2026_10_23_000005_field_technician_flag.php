<?php

use App\Models\Membership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "ফিল্ড টেকনিশিয়ান" is a tick on the team page, not a menu: only ticked members get the app's technician side
// (My Jobs, location, chats limited to their running work). Agents who had the old menu and a billing employee are ticked.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->boolean('field_technician')->default(false);
        });
        foreach (Membership::where('role', 'agent')->whereNotNull('billing_employee_id')->with('groups')->get() as $m) {
            if (in_array('field_jobs', \App\Support\Menu::keys($m), true)) {
                DB::table('company_user')->where('id', $m->id)->update(['field_technician' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn('field_technician'));
    }
};
