<?php

namespace App\Providers;

use App\Listeners\Auth\LogFailedLogin;
use App\Listeners\Auth\LogSuccessfulLogin;
use App\Models\Transaccion;
use App\Observers\TransaccionObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // (BUG-14) Antes el límite era sólo por IP: el ataque seguía desde otra IP y una
        // sucursal detrás de NAT quedaba bloqueada por un tercero. Ahora la cuenta atacada
        // se bloquea por sí misma (10/min) y la IP conserva un tope más amplio (30/min).
        RateLimiter::for('auth', function (Request $request) {
            $cuenta = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(10)->by('cuenta:' . ($cuenta ?: $request->ip())),
                Limit::perMinute(30)->by('ip:' . $request->ip()),
            ];
        });

        // Auditoría: listeners de eventos de autenticación.
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Failed::class, LogFailedLogin::class);

        // Auditoría: observer que crea snapshots de transacciones en MongoDB.
        Transaccion::observe(TransaccionObserver::class);
    }
}
