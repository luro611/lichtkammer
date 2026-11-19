<?php
declare(strict_types=1);

namespace Lichtkammer;

final class SecurityHeaders
{
    /** @return array<string,string> */
    public static function for(Request $request, Config $config): array
    {
        $h = [
            // Alles lokal: keine externen Schriften, Skripte oder Bilder.
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            // Kein Fenster-Austausch mit fremden Seiten, Antworten nur für die eigene Adresse (Entwurf 20, 1.1). Bilder unter
            // /media liefert Apache aus (public/media/.htaccess: same-site).
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($request->isHttps()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000';
        }
        if ($config->noindex()) {
            $h['X-Robots-Tag'] = 'noindex, nofollow';
        }
        return $h;
    }
}
