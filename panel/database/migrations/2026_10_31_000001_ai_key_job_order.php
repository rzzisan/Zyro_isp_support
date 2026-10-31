<?php

use App\Models\AiKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A job keeps an ordered list of keys and uses only those. A job with one picked key fell back to the company's
 * other keys, so its list gets them now: same type first, then the other types that fit the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_key_jobs', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->default(0);
            $table->dropUnique(['company_id', 'job']);
            $table->unique(['company_id', 'job', 'ai_key_id']);
        });

        foreach (DB::table('ai_key_jobs')->join('ai_keys', 'ai_keys.id', '=', 'ai_key_jobs.ai_key_id')
            ->select('ai_key_jobs.company_id', 'ai_key_jobs.job', 'ai_key_jobs.ai_key_id', 'ai_keys.provider')->get() as $row) {
            $others = DB::table('ai_keys')->where('company_id', $row->company_id)->where('id', '!=', $row->ai_key_id)
                ->whereIn('provider', AiKey::JOBS[$row->job][1] ?? [$row->provider])->orderBy('provider')->orderBy('id')->get()
                ->sortBy(fn ($k) => $k->provider !== $row->provider)->values();
            foreach ($others as $i => $key) {
                DB::table('ai_key_jobs')->insert(['company_id' => $row->company_id, 'job' => $row->job, 'ai_key_id' => $key->id,
                    'position' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('ai_key_jobs')->where('position', '>', 0)->delete();
        Schema::table('ai_key_jobs', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'job', 'ai_key_id']);
            $table->unique(['company_id', 'job']);
            $table->dropColumn('position');
        });
    }
};
