<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The `ajax` token header the compose window's fetch() calls send — undo,
 * unschedule, discard, the attachment actions — read off the layout's
 * `csrf-token` meta tag the way assets/csrf.js reads it.
 *
 * A page first, because the token store is session-backed and there is no
 * session until a request has been made.
 */
trait SendsAjaxCsrf
{
    /** @return array<string, string> */
    private function ajaxCsrf(KernelBrowser $client): array
    {
        // The POST this token is for is then a second request, and a rebooted
        // kernel would come up on a new connection that cannot see fixtures
        // written inside the test's transaction.
        $client->disableReboot();

        $crawler = $client->request('GET', '/mail/inbox');

        return ['HTTP_X_CSRF_TOKEN' => (string) $crawler->filter('meta[name="csrf-token"]')->attr('content')];
    }
}
