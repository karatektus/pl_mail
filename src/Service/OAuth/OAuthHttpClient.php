<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use GuzzleHttp\Client;

/**
 * The HTTP client for the league OAuth providers, which otherwise use Guzzle's
 * default: no timeout at all. A token refresh runs before a page's first call to
 * a service, and one that hung would outlast PHP's limit in a web request, a
 * fatal no catch sees (#449), and hold a worker for ever outside one.
 *
 * Google and Microsoft, so a hang is rare and a slow answer is not expected
 * either. The same ten seconds as HttpLimits::CALL.
 */
final class OAuthHttpClient
{
    private const int TIMEOUT_SECONDS = 10;

    /** @return array{httpClient: Client} */
    public static function collaborators(): array
    {
        return ['httpClient' => new Client(['timeout' => self::TIMEOUT_SECONDS])];
    }
}
