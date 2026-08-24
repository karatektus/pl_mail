<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Security\Csp\CspNonce;
use App\Service\Mail\MessageRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The message frame's script carries the nonce the PAGE's policy names.
 *
 * The body is rendered into a `srcdoc` iframe, and a srcdoc document inherits
 * the embedding page's Content-Security-Policy on top of the <meta> one it
 * carries itself. So the ~30 lines of height reporting inside it — the only
 * reason `allow-scripts` is on that sandbox at all — have to satisfy both
 * policies at once.
 *
 * They did not. MessageRenderer minted a nonce of its own, which the frame's
 * own policy named and the page's policy had never heard of, so the moment the
 * application itself grew a CSP the script was blocked by the inherited one:
 * every message sat at its 80px floor with a scrollbar inside a scrollbar, and
 * the console said so on every open.
 *
 * ── Why nothing caught it ────────────────────────────────────────────────────
 *
 * The browser suite runs with APP_DEBUG=1, and under debug the full policy is
 * sent as Report-Only — deliberately, because the profiler injects inline
 * scripts nothing can nonce. Report-Only does not block, so the frame measured
 * itself perfectly in every test while being blocked in production, where the
 * same policy is ENFORCED. message-frame-height.spec.ts passes either way.
 *
 * That gap is the reason this test is a unit test rather than a browser one: it
 * asserts the INVARIANT the enforced policy depends on, which is true or false
 * regardless of which header the environment happens to send.
 */
final class MessageFrameNonceTest extends KernelTestCase
{
    public function testTheFrameIsNoncedWithTheRequestsOwnNonce(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $renderer  = $container->get(MessageRenderer::class);
        $nonce     = $container->get(CspNonce::class);

        $message               = new Message();
        $message->subject      = 'Nonce';
        $message->fromAddress  = 'sender@example.test';
        $message->bodyHtmlSafe = '<p>Body</p>';
        $message->flags        = [];

        $render = $renderer->render($message);

        self::assertSame(
            $nonce->value(),
            $render->nonce,
            'the frame carries a nonce the page policy cannot authorise',
        );
    }

    /**
     * And the frame's own policy names that same one, so neither half of the
     * pair can drift without the other.
     */
    public function testTheFramesOwnPolicyNamesTheSameNonce(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $renderer  = $container->get(MessageRenderer::class);

        $message               = new Message();
        $message->subject      = 'Nonce';
        $message->fromAddress  = 'sender@example.test';
        $message->bodyHtmlSafe = '<p>Body</p>';
        $message->flags        = [];

        $render = $renderer->render($message);

        self::assertStringContainsString(
            sprintf("'nonce-%s'", $render->nonce),
            $render->csp,
            "the frame's policy does not name the nonce its script carries",
        );
    }
}
