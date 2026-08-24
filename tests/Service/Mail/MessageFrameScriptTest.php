<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MessageFrameScript;
use App\Service\Mail\MessageRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The message frame's script is authorised by BOTH policies that govern it.
 *
 * A srcdoc frame inherits the embedding page's Content-Security-Policy on top
 * of the <meta> one it carries itself, so the height reporter — the only reason
 * `allow-scripts` is on that sandbox — has to satisfy the two at once.
 *
 * ── The bug this replaces ────────────────────────────────────────────────────
 *
 * It was authorised by a nonce, which cannot work here. A page's nonce is fixed
 * when the page loads; a conversation is usually rendered by a LATER request,
 * fetched into a Turbo Frame, with a nonce of its own. The two coincide only on
 * a full page load — so the frame measured itself after a reload and never on
 * the first open, which is precisely how it was reported.
 *
 * A hash is a property of the text, identical in every request, so neither
 * policy needs to know when the other was rendered.
 *
 * ── Why these are unit tests ─────────────────────────────────────────────────
 *
 * Under APP_DEBUG the full policy is sent Report-Only, deliberately: the
 * profiler injects inline scripts nothing can nonce. Report-Only does not
 * block, so the browser suite renders a frame that works perfectly while
 * production — where the same policy is ENFORCED — blocks it. No browser test
 * running under debug can see this. These assert the invariant the enforced
 * policy depends on instead, which holds or fails whichever header is sent.
 */
final class MessageFrameScriptTest extends KernelTestCase
{
    public function testTheFramesOwnPolicyNamesTheHashOfTheScriptItEmbeds(): void
    {
        self::bootKernel();

        $render = self::getContainer()->get(MessageRenderer::class)->render($this->message());

        $hash = 'sha256-' . base64_encode(hash('sha256', $render->frameScript, true));

        self::assertStringContainsString(
            sprintf("script-src '%s'", $hash),
            $render->csp,
            "the frame's policy does not name the script it carries",
        );
    }

    /**
     * And the hash is of the file, so editing the script cannot leave the two
     * disagreeing — the failure that would produce is silent, remote and
     * production-only.
     */
    public function testTheHashIsDerivedFromTheScriptOnDisk(): void
    {
        self::bootKernel();

        $script = self::getContainer()->get(MessageFrameScript::class);

        self::assertSame(
            'sha256-' . base64_encode(hash('sha256', $script->source(), true)),
            $script->hash(),
            'the advertised hash is not the hash of the script',
        );
    }

    private function message(): Message
    {
        $message               = new Message();
        $message->subject      = 'Framed';
        $message->fromAddress  = 'sender@example.test';
        $message->bodyHtmlSafe = '<p>Body</p>';
        $message->flags        = [];

        return $message;
    }
}
