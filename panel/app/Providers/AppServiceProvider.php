<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, \App\Http\Responses\LoginResponse::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // the activity log (plan 2.4ক): every Eloquent write a signed-in person makes, plus sign-ins and sign-outs
        foreach (['created', 'updated', 'deleted'] as $event) {
            \Illuminate\Support\Facades\Event::listen("eloquent.{$event}: *", function (string $name, array $data) use ($event) {
                if (($data[0] ?? null) instanceof \Illuminate\Database\Eloquent\Model) {
                    \App\Support\Activity::modelEvent($event, $data[0]);
                }
            });
        }
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class,
            fn ($e) => \App\Support\Activity::log('auth.login'));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class,
            fn ($e) => $e->user ? \App\Support\Activity::log('auth.logout') : null);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class,
            fn ($e) => $e->user ? \App\Support\Activity::log('auth.failed', companyId: $e->user->companies()->first()?->getKey()) : null);
    }
}
