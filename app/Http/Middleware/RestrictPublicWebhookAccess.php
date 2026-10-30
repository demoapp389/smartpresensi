<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPublicWebhookAccess
{
    /**
     * Batasi request yang masuk melalui Cloudflare ke endpoint webhook dan health check.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('whatsapp.webhook_public_only')) {
            return $next($request);
        }

        $isCloudflareRequest = $request->headers->has('CF-Ray')
            || $request->headers->has('CF-Connecting-IP');

        if (! $isCloudflareRequest) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if (in_array($path, ['api/v1/whatsapp/webhook', 'up'], true)) {
            return $next($request);
        }

        abort(404);
    }
}
