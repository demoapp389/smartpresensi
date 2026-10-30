<?php

namespace App\Providers;

use App\Models\Guardian;
use App\Observers\GuardianObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
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
        Guardian::observe(GuardianObserver::class);
        Paginator::defaultView('vendor.pagination.tailwind');
        Paginator::defaultSimpleView('vendor.pagination.tailwind');

        // Gerbang produksi: APP_DEBUG aktif di production membocorkan data sensitif.
        // Dicatat sebagai critical agar terdeteksi lewat monitoring; `app:preflight`
        // dipakai sebagai gate pada pipeline deploy.
        if (app()->isProduction() && config('app.debug')) {
            Log::critical('APP_DEBUG=true terdeteksi di lingkungan production. Segera matikan untuk mencegah kebocoran data sensitif.');
        }

        // Rate limit endpoint ingest mesin absensi per token perangkat (fallback ke IP).
        // Batas sengaja longgar untuk mesin, namun melindungi dari request flooding.
        RateLimiter::for('fingerprint-ingest', function (Request $request) {
            $key = $request->header('X-Device-Token') ?: $request->bearerToken() ?: $request->ip();

            return Limit::perMinute(120)->by($key);
        });

        // Rate limit endpoint polling progres (dipanggil berkala oleh dashboard).
        RateLimiter::for('api-progress', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
