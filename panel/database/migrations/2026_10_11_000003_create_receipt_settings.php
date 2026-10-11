<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Money receipt: per-company header/footer/QR for the thermal print, and billing's own receipt number per collection. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('qr_link')->nullable();   // e.g. bKash merchant QR link, printed as a QR code
            $table->string('qr_note')->nullable();   // text beside the QR
            $table->text('footer')->nullable();      // lines under the dotted line
            $table->unsignedSmallInteger('paper_width')->default(80); // 80 or 58 mm
            $table->timestamps();
        });
        Schema::table('bill_collections', function (Blueprint $table) {
            $table->string('money_receipt_no')->nullable(); // billing's MoneyReceiptNo, known after approval
        });
    }

    public function down(): void
    {
        Schema::table('bill_collections', fn (Blueprint $table) => $table->dropColumn('money_receipt_no'));
        Schema::dropIfExists('receipt_settings');
    }
};
