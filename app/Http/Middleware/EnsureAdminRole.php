<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    /**
     * Pastikan pengguna yang login memiliki role 'admin'.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Akses ditolak: Operasi ini hanya diizinkan untuk Administrator sekolah.',
                ], 403);
            }

            abort(403, 'Akses ditolak: Halaman ini hanya dapat diakses oleh Administrator sekolah.');
        }

        return $next($request);
    }
}
