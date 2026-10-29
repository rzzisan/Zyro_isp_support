<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Who the app rings for a call: each member's call duty hours (team page) and the app's "কল নিচ্ছি" switch.
// A call log row keeps what the agent saw before taking it (ring_note) and when a call-back was done.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->json('call_hours')->nullable();   // {days: [1..7 ISO], from: "10:00", to: "20:00"}; null = any time
            $table->boolean('calls_paused')->default(false);
        });
        Schema::table('call_logs', function (Blueprint $table) {
            $table->text('ring_note')->nullable();
            $table->timestamp('callback_done_at')->nullable();
            $table->foreignId('callback_done_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // the call-back list starts with the last 3 days; older requests count as handled
        DB::table('call_logs')->whereNotNull('callback_reason')->where('started_at', '<', now()->subDays(3))
            ->update(['callback_done_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('callback_done_by');
            $table->dropColumn(['ring_note', 'callback_done_at']);
        });
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn(['call_hours', 'calls_paused']));
    }
};
