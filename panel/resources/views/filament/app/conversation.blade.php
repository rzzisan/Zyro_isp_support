<x-filament-panels::page>
    <style>
        .zc-wrap { display: flex; flex-direction: column; gap: .6rem; max-height: 65vh; overflow-y: auto; padding: 1rem;
            border-radius: .75rem; background: #f3f4f6; }
        .zc-row { display: flex; }
        .zc-row.out { justify-content: flex-end; }
        .zc-b { max-width: 75%; padding: .55rem .8rem; border-radius: .75rem; word-break: break-word;
            font-size: .92rem; line-height: 1.45; background: #fff; color: #111827; box-shadow: 0 1px 1px rgba(0,0,0,.06); }
        .zc-row.out .zc-b { background: #d9fdd3; }
        .zc-row.staff .zc-b { background: #dbeafe; }
        .zc-row .zc-b.draft { background: transparent; border: 1.5px dashed #9ca3af; color: #374151; }
        .zc-b.error { border-color: #ef4444; }
        .zc-text { white-space: pre-wrap; }
        .zc-meta { font-size: .72rem; color: #6b7280; margin-top: .25rem; display: flex; gap: .5rem; flex-wrap: wrap; }
        .zc-note { font-size: .78rem; margin-top: .3rem; color: #92400e; }
        .zc-b img, .zc-b video { max-width: 260px; border-radius: .5rem; display: block; margin-bottom: .3rem; }
        .zc-b audio { max-width: 260px; display: block; margin-bottom: .3rem; }
        .zc-info { display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: .85rem; color: #4b5563; }
        .zc-box textarea { width: 100%; min-height: 6rem; border-radius: .5rem; border: 1px solid #d1d5db; padding: .6rem;
            font-size: .92rem; background: #fff; color: #111827; }
        .zc-box select { border-radius: .5rem; border: 1px solid #d1d5db; padding: .35rem 2rem .35rem .6rem; font-size: .85rem;
            background-color: #fff; color: #111827; }
        .zc-warn { padding: .6rem .8rem; border-radius: .5rem; background: #fef3c7; color: #92400e; font-size: .85rem; }
        .zc-link { color: #059669; text-decoration: underline; cursor: pointer; font-size: .72rem; }
        .dark .zc-wrap { background: #18181b; }
        .dark .zc-b { background: #27272a; color: #f4f4f5; }
        .dark .zc-row.out .zc-b { background: #064e3b; }
        .dark .zc-row.staff .zc-b { background: #1e3a8a; }
        .dark .zc-row .zc-b.draft { background: transparent; color: #d4d4d8; }
        .dark .zc-meta, .dark .zc-info { color: #a1a1aa; }
        .dark .zc-box textarea, .dark .zc-box select { background-color: #27272a; color: #f4f4f5; border-color: #3f3f46; }
        .dark .zc-warn { background: #422006; color: #fde68a; }
        .dark .zc-note { color: #fbbf24; }
    </style>

    <div class="zc-info">
        <span>নম্বর: <b>{{ $contact->displayNumber() }}</b></span>
        <span>কাস্টমার ID: <b>{{ $contact->customer_id ?: 'অচেনা' }}</b></span>
        <span>দায়িত্বে: <b>{{ $contact->assignedUser?->name ?? 'কেউ না' }}</b></span>
        <span>বট:
            <b>
                @if ($contact->bot_paused)
                    থামানো
                @elseif ($contact->isBotPaused())
                    থামানো ({{ $contact->bot_paused_until->timezone('Asia/Dhaka')->format('g:i A') }} পর্যন্ত)
                @else
                    চালু
                @endif
            </b>
        </span>
    </div>

    <div class="zc-wrap" wire:poll.5s id="zc-wrap"
         x-data x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
        @forelse ($items as $item)
            @php($m = $item['m'])
            @if ($item['kind'] === 'message')
                <div class="zc-row {{ $m->direction }} {{ $m->sender === 'staff' ? 'staff' : '' }}" wire:key="m{{ $m->id }}">
                    <div class="zc-b">
                        @if ($m->hasMedia())
                            @php($src = route('media.show', $m))
                            @if ($m->type === 'audio')
                                <audio controls preload="none" src="{{ $src }}"></audio>
                            @elseif ($m->type === 'image' || $m->type === 'sticker')
                                <a href="{{ $src }}" target="_blank"><img src="{{ $src }}" loading="lazy" alt="ছবি"></a>
                            @elseif ($m->type === 'video')
                                <video controls preload="none" src="{{ $src }}"></video>
                            @else
                                <a class="zc-link" href="{{ $src }}" target="_blank">ফাইল খুলুন</a>
                            @endif
                        @endif
                        <div class="zc-text">{{ trim($m->body ?? ($m->hasMedia() ? '' : "[{$m->type}]")) }}</div>
                        <div class="zc-meta">
                            <span>{{ $m->created_at?->timezone('Asia/Dhaka')->format('d M, g:i A') }}</span>
                            @if ($m->direction === 'out')
                                <span>
                                    {{ match ($m->sender) { 'bot' => 'বট', 'staff' => $m->user?->name ?? 'স্টাফ', 'app' => 'WhatsApp অ্যাপ', 'campaign' => 'ক্যাম্পেইন', default => $m->sender } }}
                                </span>
                                @if ($m->status)
                                    <span>{{ ['test' => 'টেস্ট, যায়নি', 'sent' => 'পাঠানো', 'delivered' => 'পৌঁছেছে', 'read' => 'পড়েছে', 'failed' => 'ব্যর্থ'][$m->status] ?? $m->status }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="zc-row out" wire:key="d{{ $m->id }}">
                    <div class="zc-b draft {{ $m->mode === 'error' ? 'error' : '' }}">
                        @if ($m->mode === 'error')
                            বট উত্তর বানাতে পারেনি: {{ \Illuminate\Support\Str::limit($m->error, 200) }}
                        @else
                            <div class="zc-text">{{ trim((string) $m->draft) }}</div>
                        @endif
                        @if ($m->ticket_note)
                            <div class="zc-note">টিকিট: {{ $m->ticket_note }}</div>
                        @endif
                        <div class="zc-meta">
                            <span>{{ $m->created_at?->timezone('Asia/Dhaka')->format('d M, g:i A') }}</span>
                            <span>বটের খসড়া ({{ ['shadow' => 'চুপ মোড', 'dry_run' => 'টেস্ট', 'live' => 'পাঠানো যায়নি', 'error' => 'এরর'][$m->mode] ?? $m->mode }})</span>
                            @if ($m->draft)
                                <span class="zc-link" wire:click="useDraft({{ $m->id }})">এটা পাঠাতে নিন</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @empty
            <div class="zc-meta">এখনও কোনো মেসেজ নেই।</div>
        @endforelse
    </div>

    <form wire:submit="send" class="zc-box" style="display:flex; flex-direction:column; gap:.6rem">
        @unless ($sending)
            <div class="zc-warn">টেস্ট মোড: এখান থেকে লেখা মেসেজ সেভ হবে, কিন্তু কাস্টমারের কাছে যাবে না।</div>
        @endunless
        @unless ($windowOpen)
            <div class="zc-warn">কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে। WhatsApp-এর নিয়মে এখন সাধারণ মেসেজ যাবে না, অনুমোদিত template লাগবে।</div>
        @endunless
        <textarea wire:model="reply" placeholder="কাস্টমারকে উত্তর লিখুন..." @disabled(! $windowOpen)></textarea>
        @error('reply') <div class="zc-note">{{ $message }}</div> @enderror
        <div style="display:flex; gap:.75rem; align-items:center; flex-wrap:wrap">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" :disabled="! $windowOpen" wire:loading.attr="disabled" wire:target="send">
                পাঠান
            </x-filament::button>
            <label style="font-size:.85rem; display:flex; gap:.4rem; align-items:center">
                এরপর বট চুপ থাকবে
                <select wire:model="pauseHours">
                    <option value="0">চুপ থাকবে না</option>
                    <option value="1">১ ঘণ্টা</option>
                    <option value="2">২ ঘণ্টা</option>
                    <option value="6">৬ ঘণ্টা</option>
                    <option value="24">২৪ ঘণ্টা</option>
                </select>
            </label>
        </div>
    </form>
</x-filament-panels::page>
