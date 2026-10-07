<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The company's MikroTik routers (RouterOS API), to check whether a customer is online right now. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_routers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->unsignedInteger('api_port')->default(8728);
            $table->string('username');
            $table->text('password');                       // encrypted with APP_KEY
            $table->string('identity')->nullable();         // /system/identity name, read on test
            $table->string('billing_server')->nullable();   // the "Server" name customers have in billing (e.g. CLN_4)
            $table->string('version')->nullable();
            $table->unsignedInteger('ppp_active')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->string('last_check_message')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'host', 'api_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_routers');
    }
};
