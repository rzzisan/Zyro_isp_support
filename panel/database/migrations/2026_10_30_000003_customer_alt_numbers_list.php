<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customers' other numbers as their own list (কাস্টমার ও বিল → বিকল্প নম্বর): searchable, staff can add or remove one,
// the customer search and the WhatsApp / phone bots find the customer by them. Built for everyone each night
// (`php artisan customers:alt-numbers`, App\Support\AltNumbers::rebuild).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_alt_numbers', function (Blueprint $table) {
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();     // staff added it by hand
            $table->timestamp('removed_at')->nullable();                                          // staff took it off: never used again
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_alt_numbers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'number']);
            $table->dropConstrainedForeignId('added_by');
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn('removed_at');
        });
    }
};
