<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ZDbot step 10 (plan §19): super-admin routing overlay on top of docs/kb files. A row adds a related link
 * (action 'add') or switches off a link the repo file has (action 'disable'). Keyword rows add extra keywords
 * to one KB file. Effective at once; the repo file is the source of truth and rows move into it on a later PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_route_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('from_id', 120);
            $table->string('to_id', 120);
            $table->string('action', 10)->default('add');        // add | disable
            $table->string('when', 300)->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by', 120)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['from_id', 'to_id']);
        });

        Schema::create('kb_keyword_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('kb_id', 120);
            $table->string('keyword', 120);
            $table->boolean('active')->default(true);
            $table->string('created_by', 120)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['kb_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_keyword_overrides');
        Schema::dropIfExists('kb_route_overrides');
    }
};
