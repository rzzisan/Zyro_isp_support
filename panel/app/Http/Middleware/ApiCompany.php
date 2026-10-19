<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Membership;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Android app routes under /api/v1/c/{company}: the token's user must be a member of that company. Puts the
 * membership on the request and switches to the user's panel language (error messages come back in it).
 */
class ApiCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');
        $company = $company instanceof Company ? $company : Company::where('slug', (string) $company)->first();
        $user = $request->user();
        $m = $company && $user ? Membership::where('company_id', $company->id)->where('user_id', $user->id)->first() : null;
        abort_unless($m, 403, __('এই কোম্পানিতে আপনার অ্যাক্সেস নেই'));
        $request->attributes->set('membership', $m);
        Locale::apply(Locale::for($user));

        return $next($request);
    }
}
