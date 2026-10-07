@php
    $chats = $this->chatList();
    $waiting = $this->waitingCount();
    $current = $contact ?? null;
@endphp
<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/zyro-inbox.css') }}?v={{ filemtime(public_path('css/zyro-inbox.css')) }}">
    <div class="zi {{ $current ? 'zi-has-chat' : '' }}">
        {{-- ============ left: chat list ============ --}}
        <aside class="zi-list" wire:poll.10s>
            <div class="zi-list-head">
                <div class="zi-search">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="zi-search-icon" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="নাম, নম্বর বা কাস্টমার ID">
                </div>
                <div class="zi-chips">
                    @foreach ($this::BOXES as $key => $label)
                        <button type="button" wire:click="setBox('{{ $key }}')" class="zi-chip {{ $box === $key ? 'on' : '' }}">
                            {{ $label }}
                            @if ($key === 'waiting' && $waiting)<span class="zi-chip-n">{{ $waiting }}</span>@endif
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="zi-items">
                @forelse ($chats as $c)
                    @php($m = $c->lastMessage)
                    @php($isWaiting = $m?->direction === 'in')
                    <a href="{{ $this->chatUrl($c) }}" wire:navigate wire:key="c{{ $c->id }}"
                       class="zi-item {{ $current && $current->id === $c->id ? 'on' : '' }}">
                        <span class="zi-av" style="background: {{ $this::avatarColor($c->wa_number) }}">{{ $this::initials($c->name, $c->wa_number) }}</span>
                        <span class="zi-item-body">
                            <span class="zi-item-top">
                                <span class="zi-name">{{ $c->name ?: $c->displayNumber() }}</span>
                                <span class="zi-time {{ $isWaiting ? 'hot' : '' }}">{{ $this::shortTime($c->last_message_at) }}</span>
                            </span>
                            <span class="zi-item-bottom">
                                <span class="zi-preview">
                                    @if ($m && $m->direction === 'out')
                                        <b>{{ $m->sender === 'bot' ? 'বট:' : ($m->sender === 'campaign' ? 'ক্যাম্পেইন:' : 'আমরা:') }}</b>
                                    @endif
                                    {{ \Illuminate\Support\Str::limit($m?->body ?: ($m ? '['.$m->type.']' : ''), 60) }}
                                </span>
                                @if ($c->isBotPaused())<span class="zi-tag" title="বট থামানো">⏸</span>@endif
                                @if ($c->assignedUser)<span class="zi-tag" title="দায়িত্বে {{ $c->assignedUser->name }}">{{ $this::initials($c->assignedUser->name, '?') }}</span>@endif
                                @if ($isWaiting)<span class="zi-dot" title="উত্তরের অপেক্ষায়"></span>@endif
                            </span>
                        </span>
                    </a>
                @empty
                    <div class="zi-empty-list">কোনো কনভারসেশন পাওয়া যায়নি।</div>
                @endforelse
            </div>
        </aside>

        {{-- ============ middle: the conversation ============ --}}
        <section class="zi-chat">
            @if (! $current)
                <div class="zi-placeholder">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="zi-placeholder-icon" />
                    <p>বাম পাশ থেকে একটা কনভারসেশন বাছুন</p>
                    <span>{{ $waiting ? "{$waiting}টা চ্যাট উত্তরের অপেক্ষায়" : 'সবাই উত্তর পেয়েছে' }}</span>
                </div>
            @else
                <header class="zi-chat-head">
                    <a href="{{ \App\Filament\App\Resources\Conversations\ConversationResource::getUrl('index') }}" wire:navigate class="zi-back">←</a>
                    <span class="zi-av" style="background: {{ $this::avatarColor($current->wa_number) }}">{{ $this::initials($current->name, $current->wa_number) }}</span>
                    <div class="zi-chat-title">
                        <b>{{ $current->name ?: 'নাম নেই' }}</b>
                        <span>{{ $current->displayNumber() }}@if ($current->customer_id) · ID {{ $current->customer_id }}@endif</span>
                    </div>
                    <span class="zi-pill {{ $current->isBotPaused() ? 'warn' : 'ok' }}">
                        @if ($current->bot_paused) বট থামানো
                        @elseif ($current->isBotPaused()) বট চুপ {{ $current->bot_paused_until->timezone('Asia/Dhaka')->format('g:i A') }} পর্যন্ত
                        @else বট চালু @endif
                    </span>
                </header>

                <div class="zi-msgs" id="zi-msgs" wire:poll.5s
                     x-data="{ stick: true }"
                     x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true })"
                     @scroll="stick = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 80">
                    @php($lastDay = null)
                    @forelse ($items as $item)
                        @php($m = $item['m'])
                        @php($day = $item['at']?->copy()->timezone('Asia/Dhaka')->format('Y-m-d'))
                        @if ($day && $day !== $lastDay)
                            @php($lastDay = $day)
                            @php($d = $item['at']->copy()->timezone('Asia/Dhaka'))
                            <div class="zi-day"><span>{{ $d->isToday() ? 'আজ' : ($d->isYesterday() ? 'গতকাল' : $d->format('d M Y')) }}</span></div>
                        @endif
                        @if ($item['kind'] === 'message')
                            <div class="zi-row {{ $m->direction }} {{ $m->sender }}" wire:key="m{{ $m->id }}">
                                <div class="zi-b">
                                    @if ($m->direction === 'out' && $m->sender !== 'bot')
                                        <div class="zi-who">{{ $m->sender === 'staff' ? ($m->user?->name ?? 'স্টাফ') : ($m->sender === 'campaign' ? 'ক্যাম্পেইন' : 'WhatsApp অ্যাপ') }}</div>
                                    @elseif ($m->sender === 'bot')
                                        <div class="zi-who">বট</div>
                                    @endif
                                    @if ($m->hasMedia())
                                        @php($src = route('media.show', $m))
                                        @if ($m->type === 'audio')
                                            <audio controls preload="none" src="{{ $src }}"></audio>
                                        @elseif (in_array($m->type, ['image', 'sticker'], true))
                                            <a href="{{ $src }}" target="_blank"><img src="{{ $src }}" loading="lazy" alt="ছবি"></a>
                                        @elseif ($m->type === 'video')
                                            <video controls preload="none" src="{{ $src }}"></video>
                                        @else
                                            <a class="zi-file" href="{{ $src }}" target="_blank">📎 ফাইল খুলুন</a>
                                        @endif
                                    @endif
                                    @if ($m->body || ! $m->hasMedia())
                                        <div class="zi-text">{{ trim($m->body ?? "[{$m->type}]") }}</div>
                                    @endif
                                    <div class="zi-meta">
                                        {{ $m->created_at?->timezone('Asia/Dhaka')->format('g:i A') }}
                                        @if ($m->direction === 'out')
                                            @switch($m->status)
                                                @case('read') <span class="zi-tick read" title="পড়েছে">✓✓</span> @break
                                                @case('delivered') <span class="zi-tick" title="পৌঁছেছে">✓✓</span> @break
                                                @case('sent') <span class="zi-tick" title="পাঠানো">✓</span> @break
                                                @case('failed') <span class="zi-tick bad" title="যায়নি">⚠</span> @break
                                                @case('test') <span class="zi-tick" title="টেস্ট মোড, কাস্টমারের কাছে যায়নি">টেস্ট</span> @break
                                            @endswitch
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="zi-row out draft" wire:key="d{{ $m->id }}">
                                <div class="zi-b {{ $m->mode === 'error' ? 'err' : '' }}">
                                    <div class="zi-who">বটের খসড়া · {{ ['shadow' => 'চুপ মোড', 'dry_run' => 'টেস্ট', 'live' => 'পাঠানো যায়নি', 'error' => 'এরর', 'flow' => 'খসড়া'][$m->mode] ?? $m->mode }}</div>
                                    @if ($m->mode === 'error')
                                        <div class="zi-text">বট উত্তর বানাতে পারেনি: {{ \Illuminate\Support\Str::limit($m->error, 200) }}</div>
                                    @else
                                        <div class="zi-text">{{ trim((string) $m->draft) }}</div>
                                    @endif
                                    @if ($m->ticket_note)<div class="zi-note">🎫 {{ $m->ticket_note }}</div>@endif
                                    <div class="zi-meta">
                                        {{ $m->created_at?->timezone('Asia/Dhaka')->format('g:i A') }}
                                        @if ($m->draft)<button type="button" class="zi-use" wire:click="useDraft({{ $m->id }})">এটা পাঠাতে নিন</button>@endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="zi-placeholder small">এখনও কোনো মেসেজ নেই।</div>
                    @endforelse
                </div>

                <form wire:submit="send" class="zi-compose"
                      x-data="{ grow() { $refs.t.style.height = 'auto'; $refs.t.style.height = Math.min($refs.t.scrollHeight, 160) + 'px' } }">
                    @if (! $sending)
                        <div class="zi-warn">টেস্ট মোড: এখান থেকে লেখা মেসেজ সেভ হবে, কাস্টমারের কাছে যাবে না।</div>
                    @endif
                    @if (! $windowOpen)
                        <div class="zi-warn">কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে। WhatsApp-এর নিয়মে এখন সাধারণ মেসেজ যাবে না, অনুমোদিত template লাগবে।</div>
                    @endif
                    @if ($attachment)
                        <div class="zi-attach">
                            @if (str_starts_with((string) $attachment->getMimeType(), 'image/'))
                                <img src="{{ $attachment->temporaryUrl() }}" alt="">
                            @else
                                <span class="zi-attach-icon">📄</span>
                            @endif
                            <span class="zi-attach-name">{{ $attachment->getClientOriginalName() }}
                                <small>{{ number_format($attachment->getSize() / 1024, 0) }} KB · উপরের লেখাটা ক্যাপশন হিসেবে যাবে</small></span>
                            <button type="button" wire:click="removeAttachment" class="zi-attach-x" title="বাদ দিন">✕</button>
                        </div>
                    @endif
                    <div wire:loading wire:target="attachment" class="zi-muted">ফাইল আপলোড হচ্ছে…</div>
                    @error('attachment')<div class="zi-err">{{ $message }}</div>@enderror
                    <div class="zi-compose-row">
                        <label class="zi-clip" title="ছবি বা ফাইল পাঠান">
                            <x-filament::icon icon="heroicon-o-paper-clip" class="zi-send-icon" />
                            <input type="file" class="zi-file-input" wire:model="attachment"
                                   accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,audio/mpeg,audio/ogg" @disabled(! $windowOpen)>
                        </label>
                        <textarea x-ref="t" rows="1" wire:model="reply" placeholder="উত্তর লিখুন… (Enter = পাঠান, Shift+Enter = নতুন লাইন)"
                                  @input="grow()" x-effect="$wire.reply; $nextTick(() => grow())"
                                  @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
                                  @disabled(! $windowOpen)></textarea>
                        <button type="submit" class="zi-send" @disabled(! $windowOpen) wire:loading.attr="disabled" wire:target="send" title="পাঠান">
                            <x-filament::icon icon="heroicon-s-paper-airplane" class="zi-send-icon" />
                        </button>
                    </div>
                    @error('reply')<div class="zi-err">{{ $message }}</div>@enderror
                    <label class="zi-pause">
                        পাঠানোর পর এই নম্বরে বট চুপ থাকবে
                        <select wire:model="pauseHours">
                            <option value="0">চুপ থাকবে না</option>
                            <option value="1">১ ঘণ্টা</option>
                            <option value="2">২ ঘণ্টা</option>
                            <option value="6">৬ ঘণ্টা</option>
                            <option value="24">২৪ ঘণ্টা</option>
                        </select>
                    </label>
                </form>
            @endif
        </section>

        {{-- ============ right: customer details ============ --}}
        @if ($current)
            <aside class="zi-info">
                <div class="zi-info-card">
                    <span class="zi-av big" style="background: {{ $this::avatarColor($current->wa_number) }}">{{ $this::initials($current->name, $current->wa_number) }}</span>
                    <b>{{ $current->name ?: 'নাম নেই' }}</b>
                    <span>{{ $current->displayNumber() }}</span>
                </div>
                <dl class="zi-dl">
                    <dt>কাস্টমার ID</dt><dd>{{ $current->customer_id ?: 'অচেনা' }}</dd>
                    <dt>দায়িত্বে</dt><dd>{{ $current->assignedUser?->name ?? 'কেউ না' }}</dd>
                    <dt>প্রথম মেসেজ</dt><dd>{{ $current->created_at?->timezone('Asia/Dhaka')->format('d M Y') }}</dd>
                    <dt>মোট মেসেজ</dt><dd>{{ $messageCount }}</dd>
                </dl>
                <div class="zi-info-head">বিলিংয়ের টিকিট</div>
                @forelse ($tickets as $t)
                    <div class="zi-ticket">
                        <span class="zi-tstate {{ $t->state }}">{{ \App\Models\BillingTicket::STATES[$t->state] ?? $t->state }}</span>
                        <b>#{{ $t->complain_id }}</b> {{ $t->category }}
                        <small>{{ $t->opened_at?->timezone('Asia/Dhaka')->format('d M, g:i A') }}
                            @if ($t->solved_by) · {{ $t->solved_by }} @elseif ($t->assigned_to) · {{ $t->assigned_to }} @endif</small>
                    </div>
                @empty
                    <div class="zi-muted">{{ $current->customer_id ? 'কোনো টিকিট নেই' : 'কাস্টমার চেনা গেলে টিকিট দেখাবে' }}</div>
                @endforelse
            </aside>
        @endif
    </div>
</x-filament-panels::page>
