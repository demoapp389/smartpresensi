<?php

namespace App\Providers;

use App\Models\AttendanceLog;
use App\Models\AttendanceSetting;
use App\Models\Guardian;
use App\Observers\GuardianObserver;
use App\Services\AttendancePeriodService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton agar batas tanggal mulai absensi dibaca sekali per request.
        $this->app->singleton(AttendancePeriodService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Guardian::observe(GuardianObserver::class);
        Paginator::defaultView('vendor.pagination.tailwind');
        Paginator::defaultSimpleView('vendor.pagination.tailwind');

        // Badge "Perlu Peninjauan" dihitung di composer, bukan di dalam Blade,
        // agar query tidak tersembunyi di lapisan tampilan.
        View::composer('partials.sidebar-nav', function ($view): void {
            $user = Auth::user();
            $canOperate = $user !== null && ! $user->isHomeroomTeacher();
            $attendanceStartDate = AttendanceSetting::startDate();

            $unmappedCount = $canOperate
                ? AttendanceLog::query()
                    ->where('status', 'unmapped')
                    ->when(
                        $attendanceStartDate,
                        fn ($query) => $query->whereDate('occurred_at', '>=', $attendanceStartDate->toDateString())
                    )
                    ->count()
                : 0;

            $view->with('unmappedCount', $unmappedCount);
        });

        // Gerbang produksi: APP_DEBUG aktif di production membocorkan data sensitif.
        // Dicatat sebagai critical agar terdeteksi lewat monitoring; `app:preflight`
        // dipakai sebagai gate pada pipeline deploy.
        if (app()->isProduction() && config('app.debug')) {
            Log::critical('APP_DEBUG=true terdeteksi di lingkungan production. Segera matikan untuk mencegah kebocoran data sensitif.');
        }

        // Rate limit endpoint ingest mesin absensi per alamat IP.
        // Jangan memakai header token sebagai kunci: nilainya dikendalikan klien,
        // sehingga penyerang bisa membuka bucket baru hanya dengan mengganti token.
        // Batas sengaja longgar untuk mesin, namun melindungi dari request flooding.
        RateLimiter::for('fingerprint-ingest', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Rate limit endpoint polling progres (dipanggil berkala oleh dashboard).
        RateLimiter::for('api-progress', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Rate limit webhook WhatsApp publik. Endpoint ini tanpa sesi dan menulis
        // log serta query database per event, sehingga perlu dibatasi per IP.
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
