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
