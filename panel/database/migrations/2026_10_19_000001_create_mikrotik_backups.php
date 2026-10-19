<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per MikroTik backup run (engine/mikrotik_backup.py); files live on the private disk. Retention per company. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->nullable()->constrained('mikrotik_routers')->nullOnDelete();
            $table->string('router_name')->nullable();
            $table->string('trigger', 10)->default('auto');   // auto (nightly) | manual (button)
            $table->string('status', 10)->default('running'); // running | ok | partial | failed
            $table->string('rsc_path')->nullable();
            $table->unsignedBigInteger('rsc_size')->nullable();
            $table->string('backup_path')->nullable();
            $table->unsignedBigInteger('backup_size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'started_at']);
            $table->index(['router_id', 'status']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('mikrotik_backup_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_backups');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('mikrotik_backup_days');
        });
    }
};
