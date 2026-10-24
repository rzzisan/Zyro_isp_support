<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets kept in the Desk itself (engine/desk_tickets.py). billing_connections.tickets_in_billing (বিলিং সংযোগ page)
 * picks where NEW tickets are opened; a ticket stays where it was opened (billing_tickets.source 'desk'; null or
 * 'history' = copied from billing), so the old billing tickets are still solved in billing. ticket_numbers holds each company's last Desk ticket number; ticket_events
 * the history of every Desk action on a ticket (open, assign, edit, solve).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_connections', function (Blueprint $table) {
            $table->boolean('tickets_in_billing')->default(true);
        });
        Schema::create('ticket_numbers', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_no');
            $table->timestamps();
        });
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('complain_id');
            $table->string('kind', 16);                  // open | assign | edit | solve
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor')->nullable();         // the member's name, "WhatsApp বট", "ফোন বট", "অটো-অ্যাসাইন"
            $table->jsonb('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'complain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_numbers');
        Schema::table('billing_connections', fn (Blueprint $table) => $table->dropColumn('tickets_in_billing'));
    }
};
