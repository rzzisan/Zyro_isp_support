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
