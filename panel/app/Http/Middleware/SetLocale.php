<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Panel language of the signed-in user (web routes, both panels and Livewire updates). */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        Locale::apply(Locale::for($request->user()));

        return $next($request);
    }
}
