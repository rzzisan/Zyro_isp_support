<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Company panel auth: a platform super admin with no company is sent to /super instead of a bare 403. */
class AppPanelAuthenticate extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();
        if ($user && $user->is_super_admin && ! $user->companies()->exists()) {
            throw new HttpResponseException(redirect('/super'));
        }
        parent::authenticate($request, $guards);
    }
}
