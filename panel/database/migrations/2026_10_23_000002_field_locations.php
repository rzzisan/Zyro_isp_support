<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Field technicians' location, told to them (consent screen in the app, a notification while it runs): points while on
// duty or working, the place where work was started and finished, and where customers turned out to be. Only the owner
// sees it. Points older than 90 days are deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->timestamp('location_consent_at')->nullable();
            $table->timestamp('on_duty_since')->nullable();   // null = off duty
        });
        Schema::create('field_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_job_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('accuracy')->nullable();   // metres
            $table->boolean('mock')->default(false);
            $table->unsignedTinyInteger('battery')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'user_id', 'recorded_at']);
        });
        Schema::table('field_jobs', function (Blueprint $table) {
            foreach (['start', 'finish'] as $at) {
                $table->decimal("{$at}_lat", 10, 7)->nullable();
                $table->decimal("{$at}_lng", 10, 7)->nullable();
                $table->unsignedInteger("{$at}_accuracy")->nullable();
                $table->boolean("{$at}_mock")->nullable();
                $table->unsignedInteger("{$at}_distance")->nullable();   // metres from the customer's known place
            }
        });
        // where a customer is: learned from where a job there was finished with the line online
        Schema::create('customer_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('header_id');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('source', 20)->default('job');
            $table->timestamps();
            $table->unique(['company_id', 'header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_locations');
        Schema::table('field_jobs', function (Blueprint $table) {
            foreach (['start', 'finish'] as $at) {
                $table->dropColumn(["{$at}_lat", "{$at}_lng", "{$at}_accuracy", "{$at}_mock", "{$at}_distance"]);
            }
        });
        Schema::dropIfExists('field_locations');
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn(['location_consent_at', 'on_duty_since']));
    }
};
