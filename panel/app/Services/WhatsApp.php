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
        $body = static::signed($body, $user);
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
            'bot_paused_until' => $pauseHours > 0 && ! $contact->technician() ? now()->addHours($pauseHours) : $contact->bot_paused_until,
            'assigned_user_id' => $contact->assigned_user_id ?? $user->id,
        ])->save();

        return $message;
    }

    /** The agent's name under the reply, so the customer knows who answered ("- Rezaul"). */
    public static function signed(string $text, User $user): string
    {
        $text = trim($text);
        $sig = '- '.trim($user->name);

        return $text === '' ? $sig : (str_ends_with($text, $sig) ? $text : $text."\n\n".$sig);
    }

    public const MEDIA_TYPES = [
        'image/jpeg' => 'image', 'image/png' => 'image', 'image/webp' => 'image',
        'application/pdf' => 'document', 'video/mp4' => 'video', 'audio/mpeg' => 'audio', 'audio/ogg' => 'audio',
    ];

    /**
     * Send an image / PDF / video / audio file (with an optional caption) as a staff reply: uploaded to Meta with
     * the company's token, then sent. The file is also kept on disk so the inbox shows it without downloading.
     */
    public static function sendMedia(WaContact $contact, string $path, string $mime, string $fileName, ?string $caption,
        User $user, int $pauseHours = 0): WaMessage
    {
        $type = static::MEDIA_TYPES[$mime] ?? throw new RuntimeException('এই ধরনের ফাইল পাঠানো যায় না (ছবি, PDF, MP4, MP3 চলবে)।');
        if (! $contact->windowOpen()) {
            throw new RuntimeException('কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে, তাই এখন কিছু পাঠানো যাবে না; অনুমোদিত template লাগবে।');
        }
        $caption = $type === 'audio' ? null : static::signed((string) $caption, $user);
        $account = static::accountFor($contact);
        $waId = null;
        $mediaId = null;
        $status = 'test';
        if (static::sendingEnabled()) {
            try {
                $mediaId = Http::withToken($account->access_token)->timeout(120)
                    ->attach('file', file_get_contents($path), $fileName, ['Content-Type' => $mime])
                    ->post(static::graph($account->phone_number_id.'/media'), ['messaging_product' => 'whatsapp', 'type' => $mime])
                    ->throw()->json('id');
                $media = ['id' => $mediaId] + ($caption && $type !== 'audio' ? ['caption' => $caption] : [])
                    + ($type === 'document' ? ['filename' => $fileName] : []);
                $res = Http::withToken($account->access_token)->timeout(30)
                    ->post(static::graph($account->phone_number_id.'/messages'), [
                        'messaging_product' => 'whatsapp', 'to' => $contact->wa_number, 'type' => $type, $type => $media,
                    ])->throw()->json();
            } catch (RequestException $e) {
                $msg = $e->response->json('error.message') ?? $e->getMessage();
                throw new RuntimeException('পাঠানো যায়নি: '.mb_substr($msg, 0, 300));
            }
            $waId = $res['messages'][0]['id'] ?? null;
            $status = 'sent';
        }
        $message = WaMessage::create([
            'company_id' => $contact->company_id, 'contact_id' => $contact->id, 'wa_account_id' => $account->id,
            'wa_message_id' => $waId, 'direction' => 'out', 'sender' => 'staff', 'user_id' => $user->id,
            'type' => $type, 'body' => $caption ?? ($type === 'document' ? $fileName : null),
            'media_id' => $mediaId ?? 'local', 'media_mime' => $mime, 'status' => $status,
        ]);
        Storage::disk('local')->put("media/{$contact->company_id}/{$message->id}", file_get_contents($path));
        $contact->forceFill([
            'last_message_at' => now(),
            'bot_paused_until' => $pauseHours > 0 && ! $contact->technician() ? now()->addHours($pauseHours) : $contact->bot_paused_until,
            'assigned_user_id' => $contact->assigned_user_id ?? $user->id,
        ])->save();

        return $message;
    }

    /** Ask Meta about the number with its token; stores the display number and verified name it returns. */
    public static function checkAccount(WaAccount $account): array
    {
        try {
            $info = Http::withToken($account->access_token)->timeout(20)
                ->get(static::graph($account->phone_number_id), ['fields' => 'display_phone_number,verified_name,quality_rating'])
                ->throw()->json();
        } catch (RequestException $e) {
            throw new RuntimeException(mb_substr($e->response->json('error.message') ?? $e->getMessage(), 0, 300));
        }
        $account->forceFill([
            'display_phone_number' => $info['display_phone_number'] ?? $account->display_phone_number,
            'verified_name' => $info['verified_name'] ?? $account->verified_name,
        ])->save();

        return $info;
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
