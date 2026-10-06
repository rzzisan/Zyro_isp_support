<?php

namespace App\Services;

use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** WhatsApp Cloud API calls made from the inbox, always with the company's own token. */
class WhatsApp
{
    public static function sendingEnabled(): bool
    {
        return (bool) config('services.whatsapp.send_enabled');
    }

    private static function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.ltrim($path, '/');
    }

    /** The number the customer last wrote to (a company may have several), else the company's first. */
    public static function accountFor(WaContact $contact): WaAccount
    {
        $id = $contact->messages()->where('direction', 'in')->whereNotNull('wa_account_id')->latest('id')->value('wa_account_id');
        $account = WaAccount::where('company_id', $contact->company_id)
            ->when($id, fn ($q) => $q->whereKey($id))->first()
            ?? WaAccount::where('company_id', $contact->company_id)->first();
        if (! $account) {
            throw new RuntimeException('এই কোম্পানির কোনো WhatsApp নম্বর যুক্ত নেই।');
        }

        return $account;
    }

    /**
     * A staff reply. With sending disabled (test mode) the message is stored with status "test" and not sent.
     * The bot stays quiet on this number for $pauseHours (0 = no pause).
     */
    public static function sendText(WaContact $contact, string $body, User $user, int $pauseHours = 0): WaMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('মেসেজ খালি।');
        }
        if (! $contact->windowOpen()) {
            throw new RuntimeException('কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে, তাই সাধারণ মেসেজ যাবে না; অনুমোদিত template লাগবে।');
        }
        $account = static::accountFor($contact);
        $waId = null;
        $status = 'test';
        if (static::sendingEnabled()) {
            try {
                $res = Http::withToken($account->access_token)->timeout(30)
                    ->post(static::graph($account->phone_number_id.'/messages'), [
                        'messaging_product' => 'whatsapp',
                        'to' => $contact->wa_number,
                        'type' => 'text',
                        'text' => ['body' => $body, 'preview_url' => false],
                    ])->throw()->json();
            } catch (RequestException $e) {
                $msg = $e->response->json('error.message') ?? $e->getMessage();
                $code = $e->response->json('error.code');
                if ($code === 131047 || str_contains($msg, 're-engagement')) {
                    $msg = 'কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে; অনুমোদিত template লাগবে।';
                }
                throw new RuntimeException('পাঠানো যায়নি: '.mb_substr($msg, 0, 300));
            }
            $waId = $res['messages'][0]['id'] ?? null;
            $status = 'sent';
        }
        $message = WaMessage::create([
            'company_id' => $contact->company_id, 'contact_id' => $contact->id, 'wa_account_id' => $account->id,
            'wa_message_id' => $waId, 'direction' => 'out', 'sender' => 'staff', 'user_id' => $user->id,
            'type' => 'text', 'body' => $body, 'status' => $status,
        ]);
        $contact->forceFill([
            'last_message_at' => now(),
            'bot_paused_until' => $pauseHours > 0 ? now()->addHours($pauseHours) : $contact->bot_paused_until,
            'assigned_user_id' => $contact->assigned_user_id ?? $user->id,
        ])->save();

        return $message;
    }

    /** Voice / image / file of a message, downloaded once from Meta and kept on disk. Returns [path, mime]. */
    public static function media(WaMessage $message): array
    {
        $disk = Storage::disk('local');
        $path = "media/{$message->company_id}/{$message->id}";
        if (! $disk->exists($path)) {
            $token = ($message->waAccount ?? static::accountFor($message->contact))->access_token;
            $meta = Http::withToken($token)->timeout(30)->get(static::graph($message->media_id))->throw()->json();
            $bytes = Http::withToken($token)->timeout(60)->get($meta['url'])->throw()->body();
            $disk->put($path, $bytes);
        }

        return [$disk->path($path), $message->media_mime ?: 'application/octet-stream'];
    }
}
