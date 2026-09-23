<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailBodySanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A mail body keeps one kind of relative URL: the inline-attachment route.
 *
 * Every other relative URL resolves against plMail once the body is shown — the
 * print view puts it straight into an app page — so a sender could make the
 * reader's browser request any path in the app with the reader's session.
 */
final class RelativeUrlsInMailTest extends TestCase
{
    public function testOnlyTheInlineAttachmentRouteSurvives(): void
    {
        $sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());

        $html = $sanitizer->sanitizeFragment(
            '<img src="/mail/attachment/12" alt="inline">'
            . '<img src="/settings/appearance/reset" alt="forged">'
            . '<a href="/admin?section=users">relative link</a>'
            . '<a href="https://example.com/">absolute link</a>'
            . '<div style="background-image: url(/logout)">styled</div>',
        );

        self::assertStringContainsString('src="/mail/attachment/12"', $html);
        self::assertStringNotContainsString('/settings/appearance/reset', $html);
        self::assertStringNotContainsString('/admin', $html);
        self::assertStringNotContainsString('/logout', $html);
        self::assertStringContainsString('href="https://example.com/"', $html);
    }
}
