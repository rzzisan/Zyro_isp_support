@php
    $chats = $this->chatList();
    $waiting = $this->waitingCount();
    $current = $contact ?? null;
    $techs = \App\Models\Technician::where('company_id', \Filament\Facades\Filament::getTenant()->getKey())
        ->where('active', true)->pluck('name', 'wa_number')->all();
@endphp
<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/zyro-inbox.css') }}?v={{ filemtime(public_path('css/zyro-inbox.css')) }}">
    <div class="zi {{ $current ? 'zi-has-chat' : '' }}">
        {{-- ============ left: chat list ============ --}}
        <aside class="zi-list" wire:poll.10s>
            <div class="zi-list-head">
                <div class="zi-search">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="zi-search-icon" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('নাম, নম্বর বা কাস্টমার ID') }}">
                </div>
                <div class="zi-chips">
                    @foreach ($this::BOXES as $key => $label)
                        <button type="button" wire:click="setBox('{{ $key }}')" class="zi-chip {{ $box === $key ? 'on' : '' }}">
                            {{ __($label) }}
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
                                <span class="zi-name">{{ $techs[$c->wa_number] ?? ($c->name ?: $c->displayNumber()) }}</span>
                                @if (isset($techs[$c->wa_number]))<span class="zi-tech">{{ __('স্টাফ') }}</span>@endif
                                <span class="zi-time {{ $isWaiting ? 'hot' : '' }}">{{ $this::shortTime($c->last_message_at) }}</span>
                            </span>
                            <span class="zi-item-bottom">
                                <span class="zi-preview">
                                    @if ($m && $m->direction === 'out')
                                        <b>{{ $m->sender === 'bot' ? __('বট:') : ($m->sender === 'campaign' ? __('ক্যাম্পেইন:') : __('আমরা:')) }}</b>
                                    @endif
                                    {{ \Illuminate\Support\Str::limit($m?->body ?: ($m ? '['.$m->type.']' : ''), 60) }}
                                </span>
                                @if ($c->isBotPaused())<span class="zi-tag" title="{{ __('বট থামানো') }}">⏸</span>@endif
                                @if ($c->notify_muted)<span class="zi-tag" title="{{ __('নোটিফিকেশন বন্ধ') }}">🔕</span>@endif
                                @if ($c->assignedUser)<span class="zi-tag" title="{{ __('দায়িত্বে :name', ['name' => $c->assignedUser->name]) }}">{{ $this::initials($c->assignedUser->name, '?') }}</span>@endif
                                @if ($isWaiting)<span class="zi-dot" title="{{ __('উত্তরের অপেক্ষায়') }}"></span>@endif
                            </span>
                        </span>
                    </a>
                @empty
                    <div class="zi-empty-list">{{ __('কোনো কনভারসেশন পাওয়া যায়নি।') }}</div>
                @endforelse
            </div>
        </aside>

        {{-- ============ middle: the conversation ============ --}}
        <section class="zi-chat">
            @if (! $current)
                <div class="zi-placeholder">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="zi-placeholder-icon" />
                    <p>{{ __('বাম পাশ থেকে একটা কনভারসেশন বাছুন') }}</p>
                    <span>{{ $waiting ? __(':countটা চ্যাট উত্তরের অপেক্ষায়', ['count' => $waiting]) : __('সবাই উত্তর পেয়েছে') }}</span>
                </div>
            @else
                <header class="zi-chat-head">
                    <a href="{{ \App\Filament\App\Resources\Conversations\ConversationResource::getUrl('index') }}" wire:navigate class="zi-back">←</a>
                    <span class="zi-av" style="background: {{ $this::avatarColor($current->wa_number) }}">{{ $this::initials($current->name, $current->wa_number) }}</span>
                    <div class="zi-chat-title">
                        <b>{{ isset($techs[$current->wa_number]) ? $techs[$current->wa_number].' · '.__('স্টাফ (টেকনিশিয়ান)') : ($current->name ?: __('নাম নেই')) }}</b>
                        <span>{{ $current->displayNumber() }}@if ($current->customer_id) · ID {{ $current->customer_id }}@endif</span>
                    </div>
                    <span class="zi-pill {{ $current->isBotPaused() ? 'warn' : 'ok' }}">
                        @if ($current->bot_paused) {{ __('বট থামানো') }}
                        @elseif ($current->isBotPaused()) {{ __('বট চুপ :time পর্যন্ত', ['time' => $current->bot_paused_until->timezone('Asia/Dhaka')->format($current->bot_paused_until->timezone('Asia/Dhaka')->isToday() ? 'g:i A' : 'j M, g:i A')]) }}
                        @else {{ __('বট চালু') }} @endif
                    </span>
                </header>

                <div class="zi-msgs" id="zi-msgs" wire:poll.5s
                     x-data="{ stick: true, ready: false, bottom() { $el.scrollTop = $el.scrollHeight } }"
                     x-init="
                        bottom(); $nextTick(() => bottom()); [50, 200, 600, 1200].forEach(ms => setTimeout(() => { if (stick) bottom() }, ms));
                        setTimeout(() => ready = true, 1300);
                        new MutationObserver(() => { if (stick) bottom() }).observe($el, { childList: true, subtree: true });
                        new ResizeObserver(() => { if (stick) bottom() }).observe($el);
                        $el.querySelectorAll('img, video').forEach(m => m.addEventListener('load', () => { if (stick) bottom() }));
                     "
                     @scroll="if (ready) stick = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 80">
                    @php($lastDay = null)
                    @forelse ($items as $item)
                        @php($m = $item['m'])
                        @php($day = $item['at']?->copy()->timezone('Asia/Dhaka')->format('Y-m-d'))
                        @if ($day && $day !== $lastDay)
                            @php($lastDay = $day)
                            @php($d = $item['at']->copy()->timezone('Asia/Dhaka'))
                            <div class="zi-day"><span>{{ $d->isToday() ? __('আজ') : ($d->isYesterday() ? __('গতকাল') : $d->format('d M Y')) }}</span></div>
                        @endif
                        @if ($item['kind'] === 'message')
                            <div class="zi-row {{ $m->direction }} {{ $m->sender }}" wire:key="m{{ $m->id }}">
                                <div class="zi-b">
                                    @if ($m->sender === 'technician')
                                        <div class="zi-who">{{ __('টেকনিশিয়ান') }} · {{ $techs[$current->wa_number] ?? __('স্টাফ') }}</div>
                                    @elseif ($m->direction === 'out' && $m->sender !== 'bot')
                                        <div class="zi-who">{{ $m->sender === 'staff' ? ($m->user?->name ?? __('স্টাফ')) : ($m->sender === 'campaign' ? __('ক্যাম্পেইন') : __('WhatsApp অ্যাপ')) }}</div>
                                    @elseif ($m->sender === 'bot')
                                        <div class="zi-who">{{ __('বট') }}</div>
                                    @endif
                                    @if ($m->hasMedia())
                                        @php($src = route('media.show', $m))
                                        @if ($m->type === 'audio')
                                            <audio controls preload="none" src="{{ $src }}"></audio>
                                        @elseif (in_array($m->type, ['image', 'sticker'], true))
                                            <a href="{{ $src }}" target="_blank" x-on:click.prevent="$dispatch('zi-photo', { src: @js($src) })"><img src="{{ $src }}" loading="lazy" alt="{{ __('ছবি') }}"></a>
                                        @elseif ($m->type === 'video')
                                            <video controls preload="none" src="{{ $src }}"></video>
                                        @else
                                            <a class="zi-file" href="{{ $src }}" target="_blank">📎 {{ __('ফাইল খুলুন') }}</a>
                                        @endif
                                    @endif
                                    @if ($m->body || ! $m->hasMedia())
                                        <div class="zi-text">{{ trim($m->body ?? "[{$m->type}]") }}</div>
                                    @endif
                                    <div class="zi-meta">
                                        {{ $m->created_at?->timezone('Asia/Dhaka')->format('g:i A') }}
                                        @if ($m->direction === 'out')
                                            @switch($m->status)
                                                @case('read') <span class="zi-tick read" title="{{ __('পড়েছে') }}">✓✓</span> @break
                                                @case('delivered') <span class="zi-tick" title="{{ __('পৌঁছেছে') }}">✓✓</span> @break
                                                @case('sent') <span class="zi-tick" title="{{ __('পাঠানো') }}">✓</span> @break
                                                @case('failed') <span class="zi-tick bad" title="{{ __('যায়নি') }}">⚠</span> @break
                                                @case('test') <span class="zi-tick" title="{{ __('টেস্ট মোড, কাস্টমারের কাছে যায়নি') }}">{{ __('টেস্ট') }}</span> @break
                                            @endswitch
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="zi-row out draft" wire:key="d{{ $m->id }}">
                                <div class="zi-b {{ $m->mode === 'error' ? 'err' : '' }}">
                                    <div class="zi-who">{{ __('বটের খসড়া') }} · {{ ['shadow' => __('চুপ মোড'), 'dry_run' => __('টেস্ট'), 'live' => __('পাঠানো যায়নি'), 'error' => __('এরর'), 'flow' => __('খসড়া')][$m->mode] ?? $m->mode }}</div>
                                    @if ($m->mode === 'error')
                                        <div class="zi-text">{{ __('বট উত্তর বানাতে পারেনি:') }} {{ \Illuminate\Support\Str::limit($m->error, 200) }}</div>
                                    @else
                                        <div class="zi-text">{{ trim((string) $m->draft) }}</div>
                                    @endif
                                    @if ($m->ticket_note)<div class="zi-note">🎫 {{ $m->ticket_note }}</div>@endif
                                    <div class="zi-meta">
                                        {{ $m->created_at?->timezone('Asia/Dhaka')->format('g:i A') }}
                                        @if ($m->draft)<button type="button" class="zi-use" wire:click="useDraft({{ $m->id }})">{{ __('এটা পাঠাতে নিন') }}</button>@endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="zi-placeholder small">{{ __('এখনও কোনো মেসেজ নেই।') }}</div>
                    @endforelse
                </div>

                <form wire:submit="send" class="zi-compose"
                      x-data="{ grow() { $refs.t.style.height = 'auto'; $refs.t.style.height = Math.min($refs.t.scrollHeight, 160) + 'px' } }">
                    @if (! $sending)
                        <div class="zi-warn">{{ __('টেস্ট মোড: এখান থেকে লেখা মেসেজ সেভ হবে, কাস্টমারের কাছে যাবে না।') }}</div>
                    @endif
                    @if (! $windowOpen)
                        <div class="zi-warn">{{ __('কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে। WhatsApp-এর নিয়মে এখন সাধারণ মেসেজ যাবে না, অনুমোদিত template লাগবে।') }}</div>
                    @endif
                    @if ($attachment)
                        <div class="zi-attach">
                            @if (str_starts_with((string) $attachment->getMimeType(), 'image/'))
                                <img src="{{ $attachment->temporaryUrl() }}" alt="">
                            @else
                                <span class="zi-attach-icon">📄</span>
                            @endif
                            <span class="zi-attach-name">{{ $attachment->getClientOriginalName() }}
                                <small>{{ number_format($attachment->getSize() / 1024, 0) }} KB · {{ __('উপরের লেখাটা ক্যাপশন হিসেবে যাবে') }}</small></span>
                            <button type="button" wire:click="removeAttachment" class="zi-attach-x" title="{{ __('বাদ দিন') }}">✕</button>
                        </div>
                    @endif
                    <div wire:loading wire:target="attachment" class="zi-muted">{{ __('ফাইল আপলোড হচ্ছে…') }}</div>
                    @error('attachment')<div class="zi-err">{{ $message }}</div>@enderror
                    <div class="zi-compose-row">
                        <label class="zi-clip" title="{{ __('ছবি বা ফাইল পাঠান') }}">
                            <x-filament::icon icon="heroicon-o-paper-clip" class="zi-send-icon" />
                            <input type="file" class="zi-file-input" wire:model="attachment"
                                   accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,audio/mpeg,audio/ogg" @disabled(! $windowOpen)>
                        </label>
                        <textarea x-ref="t" rows="1" wire:model="reply" placeholder="{{ __('উত্তর লিখুন… (Enter = পাঠান, Shift+Enter = নতুন লাইন)') }}"
                                  @input="grow()" x-effect="$wire.reply; $nextTick(() => grow())"
                                  @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
                                  @disabled(! $windowOpen)></textarea>
                        <button type="submit" class="zi-send" @disabled(! $windowOpen) wire:loading.attr="disabled" wire:target="send" title="{{ __('পাঠান') }}">
                            <x-filament::icon icon="heroicon-s-paper-airplane" class="zi-send-icon" />
                        </button>
                    </div>
                    @error('reply')<div class="zi-err">{{ $message }}</div>@enderror
                    <label class="zi-pause">
                        {{ __('পাঠানোর পর এই নম্বরে বট চুপ থাকবে') }}
                        <select wire:model="pauseMinutes">
                            <option value="0">{{ __('চুপ থাকবে না') }}</option>
                            <option value="30">{{ __('৩০ মিনিট') }}</option>
                            <option value="60">{{ __('১ ঘণ্টা') }}</option>
                            <option value="120">{{ __('২ ঘণ্টা') }}</option>
                            <option value="360">{{ __('৬ ঘণ্টা') }}</option>
                            <option value="1440">{{ __('২৪ ঘণ্টা') }}</option>
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
                    <b>{{ $current->name ?: __('নাম নেই') }}</b>
                    <span>{{ $current->displayNumber() }}</span>
                </div>
                <dl class="zi-dl">
                    <dt>{{ __('কাস্টমার ID') }}</dt>
                    <dd>
                        {{ $current->customer_id ?: __('অচেনা') }}
                        {{ $this->linkCustomerAction }} {{ $this->unlinkCustomerAction }}
                        <small class="zi-muted" style="display: block">
                            @if ($current->customer_set_at)
                                {{ __(':name দিয়েছেন, :time · বট এই লাইন ধরে', ['name' => $current->customerSetBy?->name ?? __('এজেন্ট'), 'time' => $current->customer_set_at->timezone('Asia/Dhaka')->format('d M, g:i A')]) }}
                            @elseif ($current->customer_id)
                                {{ __('বট চিনেছে') }}
                            @elseif ($sid = $current->suggestedCustomerId())
                                {{ __('এই নম্বরে লাইন :id', ['id' => $sid]) }}
                            @endif
                        </small>
                    </dd>
                    <dt>{{ __('দায়িত্বে') }}</dt><dd>{{ $current->assignedUser?->name ?? __('কেউ না') }}</dd>
                    <dt>{{ __('প্রথম মেসেজ') }}</dt><dd>{{ $current->created_at?->timezone('Asia/Dhaka')->format('d M Y') }}</dd>
                    <dt>{{ __('মোট মেসেজ') }}</dt><dd>{{ $messageCount }}</dd>
                </dl>
                {{-- tickets load on their own (the line status is in the page header, ViewConversation::headerLine) --}}
                @livewire(\App\Filament\App\Resources\Conversations\Widgets\CustomerLine::class,
                    ['customerId' => (string) $current->customer_id, 'lazy' => true], key('line-'.$current->id.'-'.$current->customer_id))
            </aside>
        @endif
    </div>
    {{-- photo viewer: images open here on top of the chat instead of a new tab --}}
    <div x-data="{ src: null }" x-on:zi-photo.window="src = $event.detail.src" x-on:keydown.escape.window="src = null"
         x-show="src" x-cloak x-transition.opacity class="zi-photo" x-on:click.self="src = null">
        <img x-bind:src="src" alt="{{ __('ছবি') }}">
        <div class="zi-photo-bar">
            <a x-bind:href="src" target="_blank" title="{{ __('নতুন ট্যাবে খুলুন') }}">↗</a>
            <a x-bind:href="src" download title="{{ __('ডাউনলোড') }}">⤓</a>
            <button type="button" x-on:click="src = null" title="{{ __('বন্ধ করুন') }}">✕</button>
        </div>
    </div>
</x-filament-panels::page>
