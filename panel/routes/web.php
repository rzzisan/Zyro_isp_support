<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// super admins land on /super, everyone else on their company panel
Route::get('/', function () {
    $user = Auth::user();

    return redirect($user && $user->is_super_admin && ! $user->companies()->exists() ? '/super' : '/app');
})->middleware('web');

// voice / image / file of an inbox message; only members of the message's company
Route::get('/media/{message}', function (\App\Models\WaMessage $message) {
    $user = Auth::user();
    abort_unless($user && $message->hasMedia() && $user->canAccessTenant($message->company), 403);
    try {
        [$path, $mime] = \App\Services\WhatsApp::media($message);
    } catch (\Throwable $e) {
        report($e);
        abort(404, 'মিডিয়া পাওয়া যায়নি (Meta থেকে মুছে গেছে বা মেয়াদ শেষ)');
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
        ->orderBy('zone')->orderBy('subzone')->orderBy('opened_at')->get();

    return view('print.tickets', ['company' => $company, 'employee' => $employee, 'tickets' => $tickets,
        'now' => now('Asia/Dhaka')]);
})->middleware('web')->name('tickets.print');

// money receipt of a bill collected from the Desk, for a thermal printer (?w=58 narrow paper, ?print=0 no print dialog);
// members with the bills menu only. Billing's own receipt number is fetched (and kept) once the bill is approved there.
Route::get('/print/{company:slug}/receipt/{collection}', function (\App\Models\Company $company, int $collection, \Illuminate\Http\Request $request) {
    $user = Auth::user();
    abort_unless($user && \App\Support\Menu::allows($user, $company, 'bills'), 403);
    $c = \App\Models\BillCollection::where('company_id', $company->id)->where('result', 'saved')->findOrFail($collection);
    // billing only has a receipt number after approval; asking before that just makes printing slower
    $approved = $c->daily_bill_id && \App\Models\BillingDailyBill::where('company_id', $company->id)
        ->where('daily_bill_id', $c->daily_bill_id)->where('is_approved', true)->exists();
    if (! $c->money_receipt_no && $approved) {
        try {
            $c->money_receipt_no = \App\Services\Engine::receiptNo($company->id, $c->id);
        } catch (\Throwable $e) {
            report($e); // billing unreachable: print with the Desk number
        }
    }
    $settings = \App\Models\ReceiptSetting::forCompany($company);
    $width = in_array((int) $request->query('w'), [58, 80], true) ? (int) $request->query('w') : ($settings->paper_width ?: 80);

    return view('print.receipt', ['company' => $company, 'c' => $c, 'settings' => $settings, 'width' => $width,
        'customer' => \App\Models\BillingCustomer::where('company_id', $company->id)->where('header_id', $c->customer_header_id)->first(),
        'receiptNo' => $c->money_receipt_no ?: 'D-'.$c->id, 'autoPrint' => $request->query('print') !== '0']);
})->middleware('web')->name('receipts.print');

// money receipt of any bill in billing's Daily Bill Collection (synced), same layout as above; members who may see
// that bill on the বিল কালেকশন page. Billing's receipt number once approved, else "B-{DailyBillId}".
Route::get('/print/{company:slug}/bill/{dailyBill}', function (\App\Models\Company $company, int $dailyBill, \Illuminate\Http\Request $request) {
    $user = Auth::user();
    abort_unless($user && \App\Support\Menu::allows($user, $company, 'bills'), 403);
    $m = \App\Models\Membership::where('company_id', $company->id)->where('user_id', $user->id)->first();
    $d = \App\Models\BillingDailyBill::where('company_id', $company->id)->where('daily_bill_id', $dailyBill)
        ->where('is_deleted', false)->visibleTo($m)->firstOrFail();
    if (! $d->money_receipt_no && $d->is_approved) {
        try {
            $d->money_receipt_no = \App\Services\Engine::dailyReceiptNo($company->id, $d->daily_bill_id);
        } catch (\Throwable $e) {
            report($e); // billing unreachable: print with the bill's own number
        }
    }
    // the receipt layout reads a collection; fill one (unsaved) from the synced bill
    $c = new \App\Models\BillCollection(['customer_id' => $d->customer_id, 'customer_name' => $d->customer_name,
        'username' => $d->username, 'payable' => round((float) $d->receive_amount + (float) $d->discount_amount + max(0, (float) $d->balance_due), 2),
        'paid' => $d->receive_amount, 'discount' => $d->discount_amount, 'payment_method' => $d->payment_method,
        'received_by' => $d->received_by, 'money_receipt_no' => $d->money_receipt_no]);
    $c->created_at = $d->collected_at;
    $c->mobile = $d->mobile;
    $settings = \App\Models\ReceiptSetting::forCompany($company);
    $width = in_array((int) $request->query('w'), [58, 80], true) ? (int) $request->query('w') : ($settings->paper_width ?: 80);

    return view('print.receipt', ['company' => $company, 'c' => $c, 'settings' => $settings, 'width' => $width,
        'customer' => $d->customer_header_id ? \App\Models\BillingCustomer::where('company_id', $company->id)->where('header_id', $d->customer_header_id)->first() : null,
        'receiptNo' => $d->money_receipt_no ?: 'B-'.$d->daily_bill_id,
        'autoPrint' => $request->query('print') !== '0']);
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
                'image' => '📷 ছবি', 'audio' => '🎤 ভয়েস', 'video' => '🎬 ভিডিও', 'document' => '📄 ফাইল',
                'sticker' => 'স্টিকার', 'location' => '📍 লোকেশন', default => 'নতুন মেসেজ',
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
