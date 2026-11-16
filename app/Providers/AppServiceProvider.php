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
        //
    }

    /**
     * Bootstrap any application services.
     */
        public function boot(): void
    {
        \App\Models\Deposit::observe(\App\Observers\DepositObserver::class);

        // 🆕 domain events → transactional outbox (ปิดอยู่จนกว่าจะตั้ง DOMAIN_EVENTS_ENABLED=true)
        \App\Models\User::observe(\App\Observers\MemberEventsObserver::class);
        \App\Models\Deposit::observe(\App\Observers\MoneyEventsObserver::class);
        \App\Models\Withdrawal::observe(\App\Observers\MoneyEventsObserver::class);

        // internal read-only admin API (unified admin) — one shared bucket for the service
        \Illuminate\Support\Facades\RateLimiter::for('internal-admin', fn () =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(max(30, (int) config('services.internal_admin.per_minute', 300)))->by('internal-admin'));
        //
    }
}
