<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Putuskan sesi pengguna yang akunnya dinonaktifkan.
 *
 * Pemeriksaan `is_active` saat login saja tidak cukup: sesi yang sudah berjalan
 * tetap valid setelah Administrator menonaktifkan akun. Middleware ini memaksa
 * logout agar perubahan status langsung berlaku.
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Akun Anda telah dinonaktifkan. Hubungi Administrator sekolah.',
                ], 403);
            }

            return redirect()->route('login')
                ->with('error', 'Akun Anda telah dinonaktifkan. Hubungi Administrator sekolah.');
        }

        return $next($request);
    }
}
