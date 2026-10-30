<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// company_user.call_hours came in as json, the other list columns are jsonb. Postgres has no "=" for json, so any
// SELECT DISTINCT company_user.* failed (the permission group form's member picker: "Error while loading page").
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_user ALTER COLUMN call_hours TYPE jsonb USING call_hours::jsonb');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_user ALTER COLUMN call_hours TYPE json USING call_hours::json');
        }
    }
};
