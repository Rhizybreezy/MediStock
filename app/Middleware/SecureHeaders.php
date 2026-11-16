<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Adds security headers to every response.
 * CSP: scripts only from this server (no inline JS), images may be data:/blob:
 * (QR codes, camera snapshots). Inline styles are allowed because Bootstrap
 * components and Chart.js set style attributes dynamically.
 */
final class SecureHeaders implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        return self::apply($next($request), $request);
    }

    public static function apply(Response $response, ?Request $request): Response
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By'); // do not advertise the PHP version
        }
        $response
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->header('Cross-Origin-Opener-Policy', 'same-origin')
            ->header('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "media-src 'self' blob:",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]));
        if (!$response->hasHeader('Cache-Control')) {
            // Authenticated pages contain inventory data: never cache them in shared caches.
            $response->header('Cache-Control', 'no-store, private');
        }
        if ($request?->isSecure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
