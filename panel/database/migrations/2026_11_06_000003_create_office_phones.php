<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // an office IP phone that registers on our Asterisk (endpoint ph<id>) instead of the cloud PBX; Asterisk
        // registers the phone's PBX extension (o<id>) in its place, so every call goes through us: recorded, logged
        Schema::create('office_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('pbx_username', 80);          // the extension on the PBX, e.g. 103
            $table->text('pbx_password')->nullable();    // encrypted
            $table->text('phone_password');              // encrypted; the phone logs in to us with ph<id> and this
            $table->boolean('enabled')->default(true);
            $table->string('status', 250)->nullable();   // written by the engine: PBX registration, phone online
            $table->timestamp('status_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'pbx_username']);
        });
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('office_ext', 80)->nullable();   // the office phone's extension that took or made the call
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn('office_ext');
        });
        Schema::dropIfExists('office_phones');
    }
};
