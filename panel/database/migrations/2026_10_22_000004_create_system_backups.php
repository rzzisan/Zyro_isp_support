<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole-Desk backups to the super admin's Google Drive (engine/system_backup.py, run by root every minute).
 * system_backup_settings is a single row (id 1); system_backups holds one row per run, restore test or download.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('db_every_hours')->default(6);   // database-only dump between full runs
            $table->string('full_at', 5)->default('03:30');              // Dhaka time, after the MikroTik backups
            $table->unsignedSmallInteger('keep_hourly_days')->default(7);
            $table->unsignedSmallInteger('keep_daily_days')->default(30);
            $table->unsignedSmallInteger('keep_weekly')->default(12);
            $table->unsignedSmallInteger('keep_monthly')->default(12);
            $table->unsignedSmallInteger('keep_deleted_files_days')->default(30);
            $table->boolean('include_files')->default(true);
            $table->boolean('include_secrets')->default(true);
            $table->boolean('include_config')->default(true);
            $table->unsignedSmallInteger('bwlimit_mb')->default(4);     // upload speed cap, MB/s (0 = none)
            $table->boolean('weekly_restore_test')->default(true);
            // Google Drive (OAuth, drive.file scope: Desk sees only what it created)
            $table->string('google_client_id')->nullable();
            $table->text('google_client_secret')->nullable();            // encrypted
            $table->text('google_refresh_token')->nullable();            // encrypted
            $table->string('google_email')->nullable();
            $table->string('google_folder_id')->nullable();
            $table->timestamp('google_connected_at')->nullable();
            $table->string('google_error')->nullable();                  // e.g. the token was revoked
            $table->unsignedBigInteger('drive_total')->nullable();
            $table->unsignedBigInteger('drive_used')->nullable();
            $table->timestamp('drive_checked_at')->nullable();
            // rclone crypt password: the recovery key, shown once, kept offline by the super admin
            $table->text('crypt_key')->nullable();                       // encrypted
            $table->timestamp('crypt_key_confirmed_at')->nullable();
            // alerts on WhatsApp from one company's number
            $table->foreignId('alert_wa_account_id')->nullable()->constrained('wa_accounts')->nullOnDelete();
            $table->text('alert_numbers')->nullable();                   // one 8801XXXXXXXXX per line
            $table->timestamp('stale_alert_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_backups', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);                       // db | full | restore_test | download
            $table->string('trigger', 10)->default('auto');   // auto | manual
            $table->string('status', 10)->default('queued');  // queued | running | ok | partial | failed
            $table->string('tier', 10)->nullable();           // hourly | daily | weekly | monthly | manual (retention)
            $table->string('remote_dir')->nullable();         // runs/2026-10-22_0330-full inside the encrypted Drive folder
            $table->unsignedBigInteger('size')->nullable();
            $table->json('parts')->nullable();                // per part: size, files, counts, checks
            $table->foreignId('source_id')->nullable()->constrained('system_backups')->nullOnDelete();
            $table->string('download_path')->nullable();      // private disk, for kind download
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error')->nullable();
            $table->boolean('pruned')->default(false);        // files gone from Drive (retention)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_backups');
        Schema::dropIfExists('system_backup_settings');
    }
};
