<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Auth\Notifications\ResetPassword;
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('registration', fn(Request $r) => [
            Limit::perMinute(2)->by('registration-minute:' . $r->ip()),
            Limit::perDay(5)->by('registration-ip:' . $r->ip()),
            Limit::perDay(3)->by('registration-domain:' . hash('sha256', strtolower(substr(strrchr((string) $r->input('email'), '@') ?: '', 1)))),
            Limit::perDay((int) config('registration.daily_limit', 100))->by('registration-global'),
        ]);
        RateLimiter::for(
            'login',
            fn(Request $r) => [
                Limit::perMinute(10)->by($r->ip()),
                Limit::perMinute(5)->by(strtolower((string) $r->input('email')) . '|' . $r->ip()),
            ],
        );
        RateLimiter::for('bootstrap', fn(Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('widget', fn(Request $r) => Limit::perMinute(30)->by($r->ip()));
        ResetPassword::createUrlUsing(
            fn($user, $token) => config('app.url') .
                ($user->isSystem() ? '/administration/login#reset?' : '/restaurant/login#reset?') .
                http_build_query(['token' => $token, 'email' => $user->email]),
        );
    }
}
