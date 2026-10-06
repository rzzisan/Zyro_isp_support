<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Land on the panel you logged into. Filament's default uses the "intended" URL, which is
 * shared by both panels, so a super admin logging in at /super was sent to /app.
 */
class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $request->session()->forget('url.intended');

        return redirect()->to(Filament::getUrl());
    }
}
