<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Tell the browser how to treat our pages: not framed by other sites, not saved after logout, camera for this site only.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ]);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Pages are never kept, so the Back button after logout cannot show resident data. Built files keep their own caching.
        if (! $request->is('build/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        header_remove('X-Powered-By');

        return $response;
    }

    /**
     * The sources this site loads from. It only reports for now, so a missed source shows in the console and blocks nothing.
     */
    private function policy(): string
    {
        // Only while developing: the Vite server runs on another address (sometimes IPv6, which a policy cannot name).
        $dev = app()->environment('local') ? ' http:' : '';
        $socket = app()->environment('local') ? ' ws:' : '';
        $nonce = Vite::cspNonce();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:{$dev}",
            "media-src 'self' data: blob:",
            "connect-src 'self'{$dev}{$socket}",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
