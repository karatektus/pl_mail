<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Service\Mail\MessageFrameScript;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The half that the frame cannot check for itself: the PAGE's policy has to
 * name the same hash, because a srcdoc frame is governed by it too.
 *
 * This is the assertion that fails on the original bug. Everything else about
 * the frame can be right while this one is wrong, and then the script is
 * blocked by a policy the frame never mentions.
 */
final class MessageFramePagePolicyTest extends WebTestCase
{
    public function testThePagePolicyAuthorisesTheFrameScript(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $headers = $client->getResponse()->headers;

        // Under debug the full policy rides in the Report-Only header; in
        // production it is the enforced one. Either carries the source list.
        $policy = (string) (
            $headers->get('Content-Security-Policy-Report-Only')
            ?? $headers->get('Content-Security-Policy')
        );

        self::assertNotSame('', $policy, 'the page sent no policy to check');

        $hash = static::getContainer()->get(MessageFrameScript::class)->hash();

        self::assertStringContainsString(
            sprintf("'%s'", $hash),
            $policy,
            'the page policy does not authorise the message frame script, so a '
            . 'conversation opened into a Turbo Frame cannot measure itself',
        );
    }
}
