@php
    $company = \Filament\Facades\Filament::getTenant();
    [$key, $group, $other] = $current ?? [null, null, null];
    $initials = fn (?string $name) => mb_strtoupper(mb_substr(trim((string) $name) ?: '?', 0, 1));
    $color = fn (string $seed) => ['#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#64748b'][crc32($seed) % 8];
    $digits = fn (int $n) => app()->getLocale() === 'bn' ? strtr((string) $n, ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯']) : (string) $n;
    $when = function (?string $iso) {
        if (! $iso) return '';
        $t = \Illuminate\Support\Carbon::parse($iso)->timezone('Asia/Dhaka');
        return $t->isToday() ? $t->format('g:i A') : ($t->isYesterday() ? __('গতকাল') : $t->format('d M'));
    };
@endphp
<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/zyro-inbox.css') }}?v={{ filemtime(public_path('css/zyro-inbox.css')) }}">
    <style>
        .zt.zi-has-chat { grid-template-columns: 340px minmax(0, 1fr); }
        @media (max-width: 1280px) { .zt.zi-has-chat { grid-template-columns: 300px minmax(0, 1fr); } }
        @media (max-width: 820px) { .zt.zi-has-chat { grid-template-columns: 1fr; } }
        .zt-n { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: #16a34a; color: #fff; font-size: .7rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
        .zt-sec { padding: 10px 14px 4px; font-size: .7rem; font-weight: 600; letter-spacing: .03em; color: var(--z-muted, #64748b); text-transform: uppercase; }
        .zt-del-text { font-style: italic; opacity: .6; }
        .zt-rec { display: flex; align-items: center; gap: 10px; flex: 1; padding: 6px 12px; border-radius: 22px; background: #fee2e2; color: #b91c1c; font-weight: 600; }
        .zt-rec-dot { width: 10px; height: 10px; border-radius: 50%; background: #dc2626; animation: zt-blink 1s infinite; }
        @keyframes zt-blink { 50% { opacity: .2; } }
        .zt-mic { color: var(--z-muted, #64748b); }
        .zt-mic:hover { color: #16a34a; }
        .zt-head-btn { margin-left: auto; font-size: .8rem; padding: 4px 10px; border-radius: 8px; border: 1px solid var(--z-border); background: var(--z-surface); cursor: pointer; }
        .zi-row .zi-b audio { max-width: 260px; height: 40px; }
    </style>
    <div class="zi zt {{ $current ? 'zi-has-chat' : '' }}">
        {{-- ============ left: chats ============ --}}
        <aside class="zi-list" wire:poll.10s>
            <div class="zi-list-head">
                <div class="zi-search">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="zi-search-icon" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('নাম খুঁজুন') }}">
                </div>
            </div>
            <div class="zi-items">
                @php($q = mb_strtolower($search))
                @foreach ($chats->filter(fn ($c) => $q === '' || str_contains(mb_strtolower($c['title']), $q)) as $c)
                    <a href="#" wire:click.prevent="open('{{ $c['ref'] }}')" wire:key="c{{ $c['ref'] }}"
                       class="zi-item {{ $chat === $c['ref'] ? 'on' : '' }}">
                        <span class="zi-av" style="background: {{ $color($c['ref']) }}">{{ $c['kind'] === 'group' ? '👥' : $initials($c['title']) }}</span>
                        <span class="zi-item-body">
                            <span class="zi-item-top">
                                <span class="zi-name">{{ $c['title'] }}</span>
                                <span class="zi-time {{ $c['unread'] ? 'hot' : '' }}">{{ $when($c['last']['at'] ?? null) }}</span>
                            </span>
                            <span class="zi-item-bottom">
                                <span class="zi-preview">
                                    @if ($c['last'])
                                        @if ($c['last']['mine'])<b>{{ __('আমি:') }}</b>@elseif ($c['kind'] === 'group' && $c['last']['sender'])<b>{{ $c['last']['sender'] }}:</b>@endif
                                        {{ $c['last']['text'] }}
                                    @else
                                        {{ $c['kind'] === 'group' ? __(':n জন সদস্য', ['n' => $digits($c['members'])]) : '' }}
                                    @endif
                                </span>
                                @if ($c['muted'])<span class="zi-tag" title="{{ __('নোটিফিকেশন বন্ধ') }}">🔕</span>@endif
                                @if ($c['unread'])<span class="zt-n">{{ $digits($c['unread']) }}</span>@endif
                            </span>
                        </span>
                    </a>
                @endforeach
                @if ($people->isNotEmpty())
                    <div class="zt-sec">{{ __('নতুন চ্যাট') }}</div>
                    @foreach ($people as $p)
                        <a href="#" wire:click.prevent="open('u{{ $p->id }}')" wire:key="p{{ $p->id }}" class="zi-item {{ $chat === 'u'.$p->id ? 'on' : '' }}">
                            <span class="zi-av" style="background: {{ $color('u'.$p->id) }}">{{ $initials($p->name) }}</span>
                            <span class="zi-item-body"><span class="zi-item-top"><span class="zi-name">{{ $p->name }}</span></span></span>
                        </a>
                    @endforeach
                @endif
                @if ($chats->isEmpty() && $people->isEmpty())
                    <div class="zi-empty-list">{{ __('কোম্পানিতে আর কোনো সদস্য নেই।') }}</div>
                @endif
            </div>
        </aside>

        {{-- ============ right: the chat ============ --}}
        <section class="zi-chat">
            @if (! $current)
                <div class="zi-placeholder">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="zi-placeholder-icon" />
                    <p>{{ __('বাম পাশ থেকে একটা চ্যাট বা সদস্য বাছুন') }}</p>
                    <span>{{ __('গ্রুপগুলো ওয়াকি-টকির গ্রুপ, সদস্যও একই।') }}</span>
                </div>
            @else
                <header class="zi-chat-head">
                    <a href="#" wire:click.prevent="open('')" class="zi-back">←</a>
                    <span class="zi-av" style="background: {{ $color($chat) }}">{{ $group ? '👥' : $initials($other->name) }}</span>
                    <div class="zi-chat-title">
                        <b>{{ $group ? $group->name : $other->name }}</b>
                        <span>{{ $group ? $group->memberships()->with('user:id,name')->get()->map(fn ($m) => $m->user?->name)->filter()->sort()->join(', ') : __('একান্ত চ্যাট') }}</span>
                    </div>
                    <button type="button" class="zt-head-btn" wire:click="toggleMute">{{ $muted ? '🔔 '.__('নোটিফিকেশন চালু') : '🔕 '.__('নোটিফিকেশন বন্ধ') }}</button>
                </header>

                <div class="zi-msgs" id="zi-msgs" wire:poll.4s
                     x-data="{ stick: true, ready: false, bottom() { $el.scrollTop = $el.scrollHeight } }"
                     x-init="
                        bottom(); $nextTick(() => bottom()); [50, 200, 600, 1200].forEach(ms => setTimeout(() => { if (stick) bottom() }, ms));
                        setTimeout(() => ready = true, 1300);
                        new MutationObserver(() => { if (stick) bottom() }).observe($el, { childList: true, subtree: true });
                     "
                     @scroll="if (ready) stick = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 80">
                    @php($lastDay = null)
                    @forelse ($messages as $m)
                        @php($d = $m->created_at->copy()->timezone('Asia/Dhaka'))
                        @if ($d->format('Y-m-d') !== $lastDay)
                            @php($lastDay = $d->format('Y-m-d'))
                            <div class="zi-day"><span>{{ $d->isToday() ? __('আজ') : ($d->isYesterday() ? __('গতকাল') : $d->format('d M Y')) }}</span></div>
                        @endif
                        @php($mine = $m->sender_id === $me->id)
                        <div class="zi-row {{ $mine ? 'out' : 'in' }}" wire:key="m{{ $m->id }}">
                            <div class="zi-b">
                                @if (! $mine && $group)<div class="zi-who">{{ $m->sender?->name ?? __('প্রাক্তন সদস্য') }}</div>@endif
                                @if ($m->deleted_at)
                                    <div class="zi-text zt-del-text">🚫 {{ __('মেসেজ মুছে ফেলা হয়েছে') }}</div>
                                @else
                                    @if ($m->media_type)
                                        @if ($m->media_gone_at)
                                            <div class="zi-text zt-del-text">{{ $m->media_type === 'voice' ? '🎤' : '📷' }} {{ __('ফাইলের মেয়াদ শেষ') }}</div>
                                        @else
                                            @php($src = route('team-chat.media', [$company, $m]))
                                            @if ($m->media_type === 'voice')
                                                <audio controls preload="none" src="{{ $src }}"></audio>
                                            @else
                                                <a href="{{ $src }}" target="_blank" x-on:click.prevent="$dispatch('zi-photo', { src: @js($src) })"><img src="{{ $src }}" loading="lazy" alt="{{ __('ছবি') }}"></a>
                                            @endif
                                        @endif
                                    @endif
                                    @if ($m->body)<div class="zi-text">{{ $m->body }}</div>@endif
                                @endif
                                <div class="zi-meta">
                                    @if ($m->media_type === 'voice' && $m->media_seconds){{ intdiv($m->media_seconds, 60) }}:{{ str_pad((string) ($m->media_seconds % 60), 2, '0', STR_PAD_LEFT) }} · @endif
                                    {{ $d->format('g:i A') }}
                                    @if ($mine && $other && ! $m->deleted_at)
                                        <span class="zi-tick {{ $seen >= $m->id ? 'read' : '' }}" title="{{ $seen >= $m->id ? __('দেখেছে') : __('পাঠানো') }}">{{ $seen >= $m->id ? '✓✓' : '✓' }}</span>
                                    @endif
                                    @if ($m->deletableBy($me, $role))
                                        <button type="button" class="zi-del" title="{{ __('মুছে ফেলুন') }}" wire:click="deleteMessage({{ $m->id }})"
                                                wire:confirm="{{ __('মেসেজটা সবার কাছ থেকে মুছে ফেলবেন?') }}">🗑</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="zi-placeholder small">{{ __('এখনও কোনো মেসেজ নেই। প্রথম মেসেজটা লিখুন।') }}</div>
                    @endforelse
                </div>

                <form wire:submit="send" class="zi-compose"
                      x-data="{
                          rec: null, chunks: [], started: 0, secs: 0, timer: null, busy: false,
                          grow() { $refs.t.style.height = 'auto'; $refs.t.style.height = Math.min($refs.t.scrollHeight, 160) + 'px' },
                          async startRec() {
                              if (! navigator.mediaDevices || ! window.MediaRecorder) { alert(@js(__('এই ব্রাউজারে ভয়েস রেকর্ড হয় না'))); return }
                              let stream;
                              try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }) } catch (e) { alert(@js(__('মাইক্রোফোনের অনুমতি দিন'))); return }
                              const type = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find(t => MediaRecorder.isTypeSupported(t)) || '';
                              this.chunks = []; this.rec = new MediaRecorder(stream, type ? { mimeType: type } : {});
                              this.rec.ondataavailable = e => { if (e.data.size) this.chunks.push(e.data) };
                              this.rec.start(); this.started = Date.now(); this.secs = 0;
                              this.timer = setInterval(() => { this.secs = Math.round((Date.now() - this.started) / 1000); if (this.secs >= 120) this.stopRec(true) }, 250);
                          },
                          stopRec(send) {
                              if (! this.rec) return;
                              const rec = this.rec, secs = Math.max(1, Math.round((Date.now() - this.started) / 1000));
                              clearInterval(this.timer); this.rec = null;
                              rec.onstop = () => {
                                  rec.stream.getTracks().forEach(t => t.stop());
                                  if (! send || ! this.chunks.length) return;
                                  const mime = (rec.mimeType || 'audio/webm').split(';')[0];
                                  const ext = mime.includes('mp4') ? 'm4a' : (mime.includes('ogg') ? 'ogg' : 'webm');
                                  const file = new File(this.chunks, 'voice.' + ext, { type: mime });
                                  this.busy = true;
                                  $wire.upload('voice', file, () => $wire.sendVoice(secs).then(() => this.busy = false), () => { this.busy = false; alert(@js(__('ভয়েস আপলোড হয়নি'))) });
                              };
                              rec.stop();
                          },
                          clock() { return Math.floor(this.secs / 60) + ':' + String(this.secs % 60).padStart(2, '0') },
                      }">
                    @if ($photo)
                        <div class="zi-attach">
                            <img src="{{ $photo->temporaryUrl() }}" alt="">
                            <span class="zi-attach-name">{{ $photo->getClientOriginalName() }}
                                <small>{{ number_format($photo->getSize() / 1024, 0) }} KB · {{ __('উপরের লেখাটা ছবির সাথে যাবে') }}</small></span>
                            <button type="button" wire:click="removePhoto" class="zi-attach-x" title="{{ __('বাদ দিন') }}">✕</button>
                        </div>
                    @endif
                    <div wire:loading wire:target="photo" class="zi-muted">{{ __('ছবি আপলোড হচ্ছে…') }}</div>
                    <div x-show="busy" x-cloak class="zi-muted">{{ __('ভয়েস পাঠানো হচ্ছে…') }}</div>
                    @error('photo')<div class="zi-err">{{ $message }}</div>@enderror
                    <div class="zi-compose-row">
                        <template x-if="rec">
                            <div class="zt-rec">
                                <span class="zt-rec-dot"></span> <span x-text="clock()"></span> <span style="font-weight: 400">{{ __('রেকর্ড হচ্ছে…') }}</span>
                                <button type="button" style="margin-left: auto" x-on:click="stopRec(false)" title="{{ __('বাতিল') }}">✕</button>
                            </div>
                        </template>
                        <label class="zi-clip" title="{{ __('ছবি পাঠান') }}" x-show="! rec">
                            <x-filament::icon icon="heroicon-o-photo" class="zi-send-icon" />
                            <input type="file" class="zi-file-input" wire:model="photo" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <textarea x-ref="t" rows="1" wire:model="text" x-show="! rec" placeholder="{{ __('মেসেজ লিখুন… (Enter = পাঠান, Shift+Enter = নতুন লাইন)') }}"
                                  @input="grow()" x-effect="$wire.text; $nextTick(() => grow())"
                                  @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"></textarea>
                        <button type="button" class="zi-clip zt-mic" x-show="! rec" x-on:click="startRec()" title="{{ __('ভয়েস রেকর্ড করুন') }}" :disabled="busy">
                            <x-filament::icon icon="heroicon-o-microphone" class="zi-send-icon" />
                        </button>
                        <button type="button" class="zi-send" x-show="rec" x-cloak x-on:click="stopRec(true)" title="{{ __('ভয়েস পাঠান') }}">
                            <x-filament::icon icon="heroicon-s-paper-airplane" class="zi-send-icon" />
                        </button>
                        <button type="submit" class="zi-send" x-show="! rec" wire:loading.attr="disabled" wire:target="send,photo" title="{{ __('পাঠান') }}">
                            <x-filament::icon icon="heroicon-s-paper-airplane" class="zi-send-icon" />
                        </button>
                    </div>
                    @error('text')<div class="zi-err">{{ $message }}</div>@enderror
                </form>
            @endif
        </section>
    </div>
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
