<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // Antes de autenticar: corta bots por IP real (nginx resuelve CF-Connecting-IP).
        RateLimiter::for('ip', fn (Request $r) => Limit::perMinute(300)->by('ip:'.$r->ip()));
        // Acciones sensibles por cuenta: soporte, checkout, reembolsos.
        RateLimiter::for('sensible', fn (Request $r) => Limit::perMinute(10)->by('u:'.($r->user()?->getKey() ?? $r->ip())));
        // Despliegues: 5 cada 10 minutos por cuenta.
        RateLimiter::for('deploy', fn (Request $r) => Limit::perMinutes(10, 5)->by('d:'.($r->user()?->getKey() ?? $r->ip())));

        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('Recuperación de Cuenta / Cambio de Contraseña')
                ->markdown('emails.reset-password', [
                    'url' => url(route('password.reset', [
                        'token' => $token,
                        'email' => $notifiable->getEmailForPasswordReset(),
                    ], false)),
                    'user' => $notifiable
                ]);
        });
    }
}