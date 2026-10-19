<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// super admins land on /super, everyone else on their company panel
Route::get('/', function () {
    $user = Auth::user();

    return redirect($user && $user->is_super_admin && ! $user->companies()->exists() ? '/super' : '/app');
})->middleware('web');

// panel language switch in the user menu (both panels); saved on the user so it follows them to every device
Route::get('/locale/{locale}', function (string $locale) {
    $user = Auth::user();
    abort_unless($user && \App\Support\Locale::normalize($locale), 404);
    $user->forceFill(['locale' => $locale])->save();

    return redirect()->back(fallback: '/');
})->middleware('web')->name('locale.switch');

// voice / image / file of an inbox message; only members of the message's company
Route::get('/media/{message}', function (\App\Models\WaMessage $message) {
    $user = Auth::user();
    abort_unless($user && $message->hasMedia() && $user->canAccessTenant($message->company), 403);
    try {
        [$path, $mime] = \App\Services\WhatsApp::media($message);
    } catch (\Throwable $e) {
        report($e);
        abort(404, __('মিডিয়া পাওয়া যায়নি (Meta থেকে মুছে গেছে বা মেয়াদ শেষ)'));
    }

    return response()->file($path, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=86400']);
})->middleware('web')->name('media.show');

// printable list of one employee's in-progress billing tickets; members of that company only
Route::get('/print/{company:slug}/tickets', function (\App\Models\Company $company, \Illuminate\Http\Request $request) {
    $user = Auth::user();
    abort_unless($user && $user->canAccessTenant($company), 403);
    $employee = trim((string) $request->query('employee'));
    abort_if($employee === '', 404);
    $tickets = \App\Models\BillingTicket::where('company_id', $company->id)->where('state', 'processing')
        ->forEmployee($employee, assignedOnly: true)
        ->orderByRaw('lower(subzone) asc nulls last')->orderBy('opened_at')->get();
    // ONU Rx power per PPPoE username: router MAC (last PPP session) -> ONU behind it (from the OLT sync)
    $rx = \Illuminate\Support\Facades\DB::table('ppp_sessions as s')
        ->join('customer_onus as m', fn ($j) => $j->on('m.company_id', '=', 's.company_id')->whereRaw('m.client_mac = upper(s.caller_id)'))
        ->join('onus as u', 'u.id', '=', 'm.onu_id')
        ->where('s.company_id', $company->id)->whereIn('s.username', $tickets->pluck('username')->filter()->all())
        ->whereNotNull('u.rx_dbm')->pluck('u.rx_dbm', 's.username');

    return view('print.tickets', ['company' => $company, 'employee' => $employee, 'tickets' => $tickets, 'rx' => $rx,
        'now' => now('Asia/Dhaka')]);
})->middleware('web')->name('tickets.print');

// money receipt of a bill collected from the Desk, for a thermal printer (?w=58 narrow paper, ?print=0 no print dialog);
// members with the bills menu only. Billing's own receipt number is fetched (and kept) once the bill is approved there.
Route::get('/print/{company:slug}/receipt/{collection}', function (\App\Models\Company $company, int $collection, \Illuminate\Http\Request $request) {
    abort_unless(\App\Support\Menu::allows(Auth::user(), $company, 'bills'), 403);

    return \App\Support\Receipts::collection($company, $collection, $request->query('w'), $request->query('print') !== '0');
})->middleware('web')->name('receipts.print');

// money receipt of any bill in billing's Daily Bill Collection (synced), same layout as above; members who may see
// that bill on the বিল কালেকশন page. Billing's receipt number once approved, else "B-{DailyBillId}".
Route::get('/print/{company:slug}/bill/{dailyBill}', function (\App\Models\Company $company, int $dailyBill, \Illuminate\Http\Request $request) {
    abort_unless(\App\Support\Menu::allows(Auth::user(), $company, 'bills'), 403);

    return \App\Support\Receipts::dailyBill($company, Auth::user(), $dailyBill, $request->query('w'), $request->query('print') !== '0');
})->middleware('web')->name('bills.receipt');

// বিল কালেকশন page, every 5 s while open and visible: has anything changed? (tiny JSON; the page refreshes only then)
Route::get('/live/{company:slug}/daily-bills', function (\App\Models\Company $company) {
    abort_unless(\App\Support\Menu::allows(Auth::user(), $company, 'bills'), 403);
    try {
        $version = \App\Services\Engine::liveDailyBills($company->id)['version'] ?? null;
    } catch (\Throwable $e) {
        $version = null; // engine or billing down: the page just keeps what it shows
    }

    return response()->json(['version' => $version])->header('Cache-Control', 'no-store');
})->middleware('web')->name('daily-bills.live');

// ONU stickers (50×75 mm, one per page) for the given assignments; QR, its note, name and helpline come from the
// money receipt settings, so the sticker always shows what the receipt shows. Members with the ONU stock menu only.
Route::get('/print/{company:slug}/onu-stickers', function (\App\Models\Company $company, \Illuminate\Http\Request $request) {
    $user = Auth::user();
    abort_unless($user && \App\Support\Onu::enabled($company) && \App\Support\Menu::allows($user, $company, 'onu_stock'), 403);
    $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
    $rows = \App\Models\OnuAssignment::where('company_id', $company->id)->whereIn('id', $ids ?: [0])->with('brand')
        ->orderBy('assigned_at')->get();
    abort_if($rows->isEmpty(), 404);

    return view('print.onu-stickers', ['company' => $company, 'rows' => $rows,
        'settings' => \App\Models\ReceiptSetting::forCompany($company), 'autoPrint' => $request->query('print') !== '0']);
})->middleware('web')->name('onu.stickers');

// one MikroTik backup file (.rsc export holds every PPPoE password); owner/admin with the MikroTik menu only
Route::get('/download/{company:slug}/mikrotik-backup/{backup}/{kind}', function (\App\Models\Company $company, int $backup, string $kind) {
    $user = Auth::user();
    abort_unless($user?->managesCompany($company) && \App\Support\Menu::allows($user, $company, 'mikrotik'), 403);
    $b = \App\Models\MikrotikBackup::where('company_id', $company->id)->findOrFail($backup);
    $path = $b->path($kind);
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    abort_unless($path && str_starts_with($path, 'mikrotik-backups/') && ! str_contains($path, '..') && $disk->exists($path), 404);

    return $disk->download($path, basename($path), ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
})->middleware('web')->name('mikrotik-backups.download');

// all billing customers as CSV (no passwords); owner/admin of that company only
Route::get('/export/{company:slug}/customers.csv', function (\App\Models\Company $company) {
    abort_unless(Auth::user()?->managesCompany($company), 403);
    $cols = ['customer_id' => 'ID', 'name' => 'Name', 'mobile' => 'Mobile', 'username' => 'PPPoE ID', 'zone' => 'Zone',
        'subzone' => 'Subzone', 'box' => 'Box', 'package' => 'Package', 'monthly_bill' => 'Monthly bill', 'due' => 'Due',
        'status' => 'Status', 'disabled' => 'Line off', 'bill_day' => 'Bill day', 'last_payment_date' => 'Last payment',
        'joined_on' => 'Joined', 'address' => 'Address', 'thana' => 'Thana', 'district' => 'District'];

    return response()->streamDownload(function () use ($company, $cols) {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads Bangla correctly with a BOM
        fputcsv($out, array_values($cols));
        \App\Models\BillingCustomer::where('company_id', $company->id)->whereNull('gone_at')->where('is_left', false)->orderBy('customer_id')
            ->select(array_keys($cols))->chunk(1000, function ($rows) use ($out, $cols) {
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn ($c) => match ($c) {
                        'disabled' => $r->disabled ? 'yes' : 'no',
                        'last_payment_date', 'joined_on' => $r->{$c}?->format('Y-m-d'),
                        default => $r->{$c},
                    }, array_keys($cols)));
                }
            });
        fclose($out);
    }, $company->slug.'-customers-'.now('Asia/Dhaka')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
})->middleware('web')->name('customers.csv');

// new incoming WhatsApp messages for the browser notifier (public/js/zyro-notify.js); inbox users of that company only.
// Without ?after it only returns the newest id, so opening the panel never replays old messages.
Route::get('/notify/{company:slug}/poll', function (\App\Models\Company $company, \Illuminate\Http\Request $request) {
    $user = Auth::user();
    abort_unless($user && \App\Support\Menu::allows($user, $company, 'inbox'), 403);
    $base = \App\Models\WaMessage::where('company_id', $company->id)->where('direction', 'in');
    $after = $request->query('after');
    if (! is_numeric($after)) {
        return response()->json(['last' => (int) $base->max('id'), 'items' => []]);
    }
    $rows = (clone $base)->where('id', '>', (int) $after)->with('contact')->orderBy('id')->limit(20)->get();
    $staff = \App\Models\Technician::where('company_id', $company->id)->where('active', true)->pluck('wa_number')->all();
    $botLive = \App\Models\BotSetting::where('company_id', $company->id)->value('bot_mode') === 'live';
    $items = $rows->reject(fn (\App\Models\WaMessage $m) => $m->contact?->notify_muted)->map(function (\App\Models\WaMessage $m) use ($company, $staff, $botLive) {
        $c = $m->contact;
        $body = trim((string) $m->body);
        if ($body === '') {
            $body = match ($m->type) {
                'image' => __('📷 ছবি'), 'audio' => __('🎤 ভয়েস'), 'video' => __('🎬 ভিডিও'), 'document' => __('📄 ফাইল'),
                'sticker' => __('স্টিকার'), 'location' => __('📍 লোকেশন'), default => __('নতুন মেসেজ'),
            };
        }

        return [
            'id' => $m->id,
            'contact' => $c->id,
            'name' => $c->name ?: $c->displayNumber(),
            'number' => $c->displayNumber(),
            'body' => \Illuminate\Support\Str::limit($body, 140),
            'staff' => in_array($c->wa_number, $staff, true),
            'human' => ! $botLive || $c->isBotPaused(),   // the bot will not answer this one
            'url' => \App\Filament\App\Resources\Conversations\ConversationResource::getUrl('view', ['record' => $c], panel: 'app', tenant: $company),
        ];
    })->values();

    return response()->json(['last' => (int) ($rows->max('id') ?? $after), 'items' => $items])
        ->header('Cache-Control', 'no-store');
})->middleware('web')->name('notify.poll');

// this browser's Web Push subscription (bell in the top bar), so the engine can alert it with the panel closed
Route::post('/notify/{company:slug}/push', function (\App\Models\Company $company, \Illuminate\Http\Request $request) {
    $user = Auth::user();
    abort_unless($user && \App\Support\Menu::allows($user, $company, 'inbox'), 403);
    $data = $request->validate(['endpoint' => 'required|url|starts_with:https://|max:2000',
        'keys.p256dh' => 'required|string|max:255', 'keys.auth' => 'required|string|max:255']);
    \Illuminate\Support\Facades\DB::table('push_subscriptions')->upsert([[
        'company_id' => $company->id, 'user_id' => $user->id, 'endpoint' => $data['endpoint'],
        'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
        'user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 250, ''),
        'created_at' => now(), 'updated_at' => now(),
    ]], ['company_id', 'endpoint'], ['user_id', 'p256dh', 'auth', 'user_agent', 'updated_at']);

    return response()->json(['ok' => true]);
})->middleware('web')->name('notify.push');

Route::post('/notify/{company:slug}/push/delete', function (\App\Models\Company $company, \Illuminate\Http\Request $request) {
    abort_unless(Auth::check(), 403);
    \Illuminate\Support\Facades\DB::table('push_subscriptions')->where('company_id', $company->id)
        ->where('user_id', Auth::id())->where('endpoint', (string) $request->input('endpoint'))->delete();

    return response()->json(['ok' => true]);
})->middleware('web')->name('notify.push.delete');
