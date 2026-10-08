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
    $items = $rows->map(function (\App\Models\WaMessage $m) use ($company, $staff, $botLive) {
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
