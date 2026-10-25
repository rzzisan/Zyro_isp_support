<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Customers may ask the bot (chat or call) to turn a bill-disabled line on once a month: the company's switch, and
 *  where each line_enables request came from (technician, Desk or the customer's own request). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('self_reconnect')->default(false);
        });
        Schema::table('line_enables', function (Blueprint $table) {
            $table->string('source', 30)->nullable();      // customer_whatsapp | customer_wa_call | customer_hotline; null = staff
            $table->index(['company_id', 'customer_header_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', fn (Blueprint $table) => $table->dropColumn('self_reconnect'));
        Schema::table('line_enables', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'customer_header_id', 'created_at']);
            $table->dropColumn('source');
        });
    }
};
