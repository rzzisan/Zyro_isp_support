<?php

namespace App\Support;

use App\Models\Company;
use App\Models\SipAgent;
use App\Models\SipSetting;
use App\Models\User;
use App\Models\WaAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Desk softphone (panel/public/softphone/phone.html, in the browser or the app's WebView): WebRTC over
 * wss://<desk>/sip-ws into Asterisk. Members allowed "phone" take WhatsApp calls while they ring and call customers
 * out through the hotline extension. The login is made on first use; the engine adds the endpoint at once (/sync).
 */
class Softphone
{
    public static function available(Company $company): bool
    {
        return SipSetting::live($company->id)
            || WaAccount::where('company_id', $company->id)->where('calling_mode', '!=', 'off')->exists();
    }

    /** Login and server for this member, or null when they may not use it. */
    public static function config(User $user, Company $company): ?array
    {
        if (! Menu::allows($user, $company, 'phone') || ! self::available($company)) {
            return null;
        }
        $agent = SipAgent::where('company_id', $company->id)->where('user_id', $user->id)->first();
        if (! $agent) {
            $agent = SipAgent::create(['company_id' => $company->id, 'user_id' => $user->id, 'password' => Str::random(24)]);
            try {
                Http::timeout(5)->get('http://127.0.0.1:8997/sync');   // engine/voice_bot.py writes the endpoint now
            } catch (Throwable) {
                // the engine picks it up within 30 s anyway
            }
        }
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: request()->getHost();

        return [
            'ws' => "wss://{$host}/sip-ws",
            'domain' => $host,
            'user' => 'a'.$agent->id,
            'password' => $agent->password,
            'name' => $user->name,
            'can_call_out' => SipSetting::live($company->id),
        ];
    }
}
