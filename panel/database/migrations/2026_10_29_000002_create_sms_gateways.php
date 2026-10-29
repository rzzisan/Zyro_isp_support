<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company can have several SMS gateways (Khudebarta, Bulk SMS Dhaka): one main, one optional fallback that takes
 * over when the main one refuses for an account reason (balance, key, IP whitelist). The company's existing
 * Khudebarta settings move into the first gateway row as they are (keys stay encrypted, copied byte for byte) and
 * become the main one, so nothing changes for a company until it adds another gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                           // khudebarta | bulksmsdhaka
            $table->string('label')->nullable();
            $table->string('endpoint_url')->nullable();           // Khudebarta: the /sendtext URL; Bulk SMS Dhaka: the API base
            $table->string('status_url')->nullable();             // optional delivery-report URL
            $table->text('api_key')->nullable();                 // encrypted
            $table->text('secret_key')->nullable();              // encrypted (Khudebarta only)
            $table->string('caller_id')->nullable();             // sender ID / masking
            $table->decimal('rate', 8, 4)->nullable();            // taka per SMS part
            $table->decimal('low_balance', 12, 2)->nullable();    // warn below this
            $table->boolean('verify_tls')->default(true);
            $table->decimal('balance', 12, 2)->nullable();
            $table->integer('balance_sms')->nullable();           // SMS left, when the gateway says
            $table->timestamp('balance_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();           // refused for an account reason: not used until cleared
            $table->timestamps();
            $table->index('company_id');
        });

        Schema::table('sms_settings', function (Blueprint $table) {
            $table->foreignId('primary_gateway_id')->nullable()->constrained('sms_gateways')->nullOnDelete();
            $table->foreignId('fallback_gateway_id')->nullable()->constrained('sms_gateways')->nullOnDelete();
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->foreignId('gateway_id')->nullable()->constrained('sms_gateways')->nullOnDelete();
            $table->string('provider')->nullable();
            $table->decimal('cost', 10, 4)->nullable();           // parts x the gateway's rate when it went
            $table->boolean('status_final')->default(false);      // the gateway will not answer about it again
        });

        $now = now();
        foreach (DB::table('sms_settings')->get() as $s) {
            if (blank($s->api_key) && blank($s->caller_id)) {
                continue;
            }
            $id = DB::table('sms_gateways')->insertGetId([
                'company_id' => $s->company_id, 'provider' => 'khudebarta', 'label' => 'Khudebarta',
                'endpoint_url' => $s->endpoint_url, 'status_url' => $s->status_url, 'api_key' => $s->api_key,
                'secret_key' => $s->secret_key, 'caller_id' => $s->caller_id, 'rate' => $s->rate, 'verify_tls' => $s->verify_tls,
                'balance' => $s->balance, 'balance_checked_at' => $s->balance_checked_at, 'last_check_ok' => $s->last_check_ok,
                'last_error' => $s->last_error, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('sms_settings')->where('id', $s->id)->update(['primary_gateway_id' => $id]);
            DB::table('sms_messages')->where('company_id', $s->company_id)->whereNull('gateway_id')
                ->update(['gateway_id' => $id, 'provider' => 'khudebarta']);
        }
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gateway_id');
            $table->dropColumn(['provider', 'cost', 'status_final']);
        });
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_gateway_id');
            $table->dropConstrainedForeignId('fallback_gateway_id');
        });
        Schema::dropIfExists('sms_gateways');
    }
};
