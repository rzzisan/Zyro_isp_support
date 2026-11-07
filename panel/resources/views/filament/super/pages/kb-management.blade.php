<x-filament-panels::page>
    @php
        $files = $this->files;
        $cov = $this->coverage;
        $edges = $this->edges;
        $ov = $this->overrides;
        $file = $fileId ? ($files[$fileId] ?? null) : null;
    @endphp

    <div class="flex flex-wrap gap-2">
        @foreach (['files' => 'KB ফাইল ও কভারেজ', 'routes' => 'রাউটিং', 'test' => 'পরীক্ষা বক্স'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $tab === $key ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'files')
        {{-- coverage --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs text-gray-500">মোট ফাইল</div>
                <div class="text-2xl font-semibold">{{ $cov['total'] }} / {{ $cov['index_rows'] }}</div>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs text-gray-500">লেখা বাকি (missing)</div>
                <div class="text-2xl font-semibold {{ count($cov['missing']) ? 'text-warning-600' : '' }}">{{ count($cov['missing']) }}</div>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs text-gray-500">ভাঙা লিংক</div>
                <div class="text-2xl font-semibold {{ count($cov['broken']) ? 'text-danger-600' : '' }}">{{ count($cov['broken']) }}</div>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs text-gray-500">পুরনো (কোড বদলেছে)</div>
                <div class="text-2xl font-semibold">{{ count($cov['stale']) }}</div>
                @unless ($cov['stale_check'])
                    <div class="text-xs text-gray-400">git না থাকায় যাচাই হয়নি</div>
                @endunless
            </div>
        </div>

        @if ($cov['no_kb_menus'])
            <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10">
                এই মেনু key-গুলোর কোনো KB ফাইল নেই: {{ implode(', ', $cov['no_kb_menus']) }}
            </div>
        @endif

        @if ($file)
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $file['title'] }}</h2>
                        <div class="text-xs text-gray-500">
                            {{ $file['id'] }} · {{ $file['module'] }} · অবস্থা: {{ $file['status'] }} · প্যাকেজ: {{ implode(', ', $file['plans']) ?: '—' }} · আপডেট: {{ $file['updated'] }}
                        </div>
                        <div class="text-xs text-gray-400">ফাইল: {{ $file['path'] }}</div>
                    </div>
                    <button type="button" wire:click="closeFile" class="text-sm text-primary-600">বন্ধ করুন</button>
                </div>

                <div>
                    <h3 class="font-semibold">সেলস অংশ</h3>
                    @if ($file['sales'] === '')
                        <p class="text-sm text-danger-600">লেখা হয়নি। লিডের কাছে এই ফিচার নিয়ে কিছু যাবে না।</p>
                    @else
                        <div class="prose dark:prose-invert max-w-none text-sm">{!! $this->catalog->render($file['sales']) !!}</div>
                    @endif
                </div>

                <div>
                    <h3 class="font-semibold">গ্রাহক অংশ</h3>
                    <div class="prose dark:prose-invert max-w-none text-sm">{!! $this->catalog->render($file['customer']) !!}</div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <h3 class="font-semibold">কীওয়ার্ড</h3>
                        <div class="text-sm">{{ implode(', ', $this->catalog->keywords($fileId)) ?: '—' }}</div>
                    </div>
                    <div>
                        <h3 class="font-semibold">এই ফাইল যেসব ফাইলে লিংক করে (আমি কাকে)</h3>
                        <ul class="text-sm list-disc ps-5">
                            @foreach (array_filter($edges, fn ($e) => $e['from'] === $fileId) as $e)
                                <li class="{{ $e['active'] ? '' : 'line-through text-gray-400' }}">{{ $e['to'] }} — {{ $e['when'] ?: '—' }} ({{ $e['source'] === 'panel' ? 'প্যানেল' : 'রিপো' }})</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="md:col-span-2">
                        <h3 class="font-semibold">কারা এই ফাইলে লিংক করে (আমাকে কে)</h3>
                        <ul class="text-sm list-disc ps-5">
                            @foreach (array_filter($edges, fn ($e) => $e['to'] === $fileId && $e['active']) as $e)
                                <li>{{ $e['from'] }} — {{ $e['when'] ?: '—' }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="min-w-full text-sm">
                    <thead class="text-start text-xs text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-start">id</th>
                            <th class="px-3 py-2 text-start">শিরোনাম</th>
                            <th class="px-3 py-2 text-start">মডিউল</th>
                            <th class="px-3 py-2 text-start">অবস্থা</th>
                            <th class="px-3 py-2 text-start">সেলস</th>
                            <th class="px-3 py-2 text-start">লিংক (আউট)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $f)
                            <tr class="border-t border-gray-950/5 dark:border-white/10">
                                <td class="px-3 py-2 font-mono text-xs">{{ $f['id'] }}</td>
                                <td class="px-3 py-2"><button type="button" wire:click="openFile('{{ $f['id'] }}')" class="text-primary-600 hover:underline">{{ $f['title'] }}</button></td>
                                <td class="px-3 py-2">{{ $f['module'] }}</td>
                                <td class="px-3 py-2">{{ $f['status'] }}</td>
                                <td class="px-3 py-2">{{ $f['sales'] === '' ? 'নেই' : 'আছে' }}</td>
                                <td class="px-3 py-2">{{ count(array_filter($edges, fn ($e) => $e['from'] === $f['id'] && $e['active'])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($cov['missing'])
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold mb-2">লেখা বাকি ফিচার</h3>
                <ul class="text-sm list-disc ps-5">
                    @foreach ($cov['missing'] as $m)
                        <li>{{ $m['id'] }} — {{ $m['title'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($cov['broken'] || $cov['stale'])
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 text-sm space-y-2">
                @foreach ($cov['broken'] as $b)
                    <div class="text-danger-600">ভাঙা লিংক: {{ $b['from'] }} → {{ $b['to'] }}</div>
                @endforeach
                @foreach ($cov['stale'] as $s)
                    <div class="text-warning-600">পুরনো: {{ $s['id'] }} (কোড {{ $s['code'] }} নতুন)</div>
                @endforeach
            </div>
        @endif
    @endif

    @if ($tab === 'routes')
        <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-3">
            <h3 class="font-semibold">নতুন লিংক যোগ</h3>
            <div class="grid gap-2 md:grid-cols-4">
                <select wire:model="newFrom" class="rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">কোন ফাইল থেকে</option>
                    @foreach ($files as $f) <option value="{{ $f['id'] }}">{{ $f['id'] }}</option> @endforeach
                </select>
                <select wire:model="newTo" class="rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">কোন ফাইলে</option>
                    @foreach ($files as $f) <option value="{{ $f['id'] }}">{{ $f['id'] }}</option> @endforeach
                </select>
                <input wire:model="newWhen" placeholder="কোন শর্তে (যেমন: বকেয়ার প্রশ্নে)" class="rounded-lg border-gray-300 text-sm dark:bg-gray-800" />
                <button type="button" wire:click="addRoute" class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white">যোগ করুন</button>
            </div>
            @error('newFrom') <div class="text-sm text-danger-600">{{ $message }}</div> @enderror
            @error('newTo') <div class="text-sm text-danger-600">{{ $message }}</div> @enderror
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-full text-sm">
                <thead class="text-xs text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-start">থেকে</th>
                        <th class="px-3 py-2 text-start">কোথায়</th>
                        <th class="px-3 py-2 text-start">কোন শর্তে</th>
                        <th class="px-3 py-2 text-start">উৎস</th>
                        <th class="px-3 py-2 text-start">অবস্থা</th>
                        <th class="px-3 py-2 text-start"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($edges as $e)
                        <tr class="border-t border-gray-950/5 dark:border-white/10 {{ $e['active'] ? '' : 'text-gray-400' }}">
                            <td class="px-3 py-2 font-mono text-xs">{{ $e['from'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $e['to'] }}</td>
                            <td class="px-3 py-2">{{ $e['when'] ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $e['source'] === 'panel' ? 'প্যানেল' : 'রিপো' }}</td>
                            <td class="px-3 py-2">{{ $e['active'] ? 'চালু' : 'বন্ধ' }}</td>
                            <td class="px-3 py-2 flex gap-3">
                                <button type="button" wire:click="toggleEdge('{{ $e['from'] }}', '{{ $e['to'] }}')" class="text-primary-600">{{ $e['active'] ? 'বন্ধ করুন' : 'চালু করুন' }}</button>
                                @if ($e['source'] === 'panel')
                                    <button type="button" wire:click="deleteRoute('{{ $e['from'] }}', '{{ $e['to'] }}')" wire:confirm="মুছবেন?" class="text-danger-600">মুছুন</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-3">
            <h3 class="font-semibold">কীওয়ার্ড যোগ (বাংলা/বাংলিশ)</h3>
            <div class="grid gap-2 md:grid-cols-3">
                <select wire:model="kwKb" class="rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">কোন ফাইলে</option>
                    @foreach ($files as $f) <option value="{{ $f['id'] }}">{{ $f['id'] }}</option> @endforeach
                </select>
                <input wire:model="kwText" placeholder="কীওয়ার্ড" class="rounded-lg border-gray-300 text-sm dark:bg-gray-800" />
                <button type="button" wire:click="addKeyword" class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white">যোগ করুন</button>
            </div>
            @if ($this->overlaps)
                <div class="text-sm text-warning-700">একাধিক ফাইলে একই কীওয়ার্ড:
                    @foreach ($this->overlaps as $o) <span class="me-2">{{ $o['keyword'] }} ({{ implode(', ', $o['ids']) }})</span> @endforeach
                </div>
            @endif
            <ul class="text-sm space-y-1">
                @foreach ($ov['keywords'] as $k)
                    <li class="{{ $k['active'] ? '' : 'line-through text-gray-400' }}">
                        {{ $k['kb_id'] }}: {{ $k['keyword'] }} ({{ $k['created_by'] }})
                        <button type="button" wire:click="toggleKeyword({{ $k['id'] }})" class="ms-2 text-primary-600">{{ $k['active'] ? 'বন্ধ' : 'চালু' }}</button>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 text-sm">
            <h3 class="font-semibold mb-2">রিপোতে তোলা বাকি প্যানেলের বদল</h3>
            <p class="text-gray-500 mb-2">এগুলো পরের KB PR-এ docs/kb ফাইলে লিখে এই টেবিল থেকে সরানো হবে।</p>
            <ul class="list-disc ps-5">
                @forelse ($this->pendingPanel() as $r)
                    <li>{{ $r['action'] === 'add' ? 'যোগ' : 'বন্ধ' }}: {{ $r['from_id'] }} → {{ $r['to_id'] }} {{ $r['when'] ? '('.$r['when'].')' : '' }}</li>
                @empty
                    <li>কিছু নেই</li>
                @endforelse
            </ul>
        </div>
    @endif

    @if ($tab === 'test')
        <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-3">
            <p class="text-sm text-gray-500">একটা প্রশ্ন লিখুন: কোন মূল ফাইল পড়া হতো, কোন সম্পর্কিত ফাইল আসতো। এটা সরল কীওয়ার্ড মিলানো, আসল রাউটার নয়।</p>
            <div class="flex gap-2">
                <input wire:model="testQ" placeholder="যেমন: বিল দিয়েছি কিন্তু লাইন চালু হয়নি" class="flex-1 rounded-lg border-gray-300 text-sm dark:bg-gray-800" />
                <button type="button" wire:click="runTest" class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white">দেখুন</button>
            </div>
            @if ($testResult)
                @if ($testResult['main'])
                    <div class="text-sm"><strong>মূল ফাইল:</strong> {{ $testResult['main'] }}</div>
                    <ul class="text-sm list-disc ps-5">
                        @foreach ($testResult['scores'] as $s) <li>{{ $s['id'] }} — {{ $s['score'] }} মিল ({{ implode(', ', $s['hits']) }})</li> @endforeach
                    </ul>
                    <div class="text-sm"><strong>সম্পর্কিত:</strong>
                        @forelse ($testResult['related'] as $r) <div>{{ $r['id'] }} — {{ $r['when'] }}</div> @empty <div>কিছু নেই</div> @endforelse
                    </div>
                @else
                    <div class="text-sm text-warning-700">কোনো ফাইল মেলেনি। নতুন KB বা কীওয়ার্ড দরকার।</div>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
