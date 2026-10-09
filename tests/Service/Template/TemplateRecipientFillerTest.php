<?php

declare(strict_types=1);

namespace App\Tests\Service\Template;

use App\Domain\DTO\Template\RenderedTemplate;
use App\Service\Template\TemplateRecipientFiller;
use App\Service\Template\TemplateTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server's reading of "who is this recipient", which has to be the
 * browser's.
 *
 * The rule exists twice — here for JMAP clients, and in
 * assets/compose/template_variables.js#recipientValues for the web compose
 * window — and the cases below are that function's documented behaviour, one
 * for one. If the two drift, the same template greets the same person
 * differently depending on which app it was inserted from, and neither side
 * would fail a test of its own.
 */
final class TemplateRecipientFillerTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, string, string, string}>
     */
    public static function recipients(): iterable
    {
        yield 'given name first'          => ['Dana Whitfield', 'dana@example.org', 'Dana', 'Dana Whitfield', 'dana@example.org'];
        yield 'surname first, as a directory writes it' => ['Whitfield, Dana', 'dana@example.org', 'Dana', 'Dana Whitfield', 'dana@example.org'];
        yield 'one word'                  => ['Dana', 'dana@example.org', 'Dana', 'Dana', 'dana@example.org'];
        yield 'an address with no name'   => [null, 'dana@example.org', '', '', 'dana@example.org'];
        yield 'a name that is an address' => ['dana@example.org', 'dana@example.org', '', '', 'dana@example.org'];
        yield 'nobody yet'                => [null, null, '', '', ''];
    }

    #[DataProvider('recipients')]
    public function testARecipientAnswersWhatItCanAndNothingIsGuessed(
        ?string $name,
        ?string $email,
        string $first,
        string $full,
        string $address,
    ): void {
        self::assertSame(
            ['recipient.first_name' => $first, 'recipient.name' => $full, 'recipient.email' => $address],
            new TemplateRecipientFiller(new TemplateTokens())->values($name, $email),
        );
    }

    /**
     * A filled variable is text from then on — the span is gone, not given a
     * value — and one that could not be filled is still there, and listed.
     */
    public function testWhatCanBeFilledBecomesTextAndTheRestStaysOpenAndIsNamed(): void
    {
        $filler   = new TemplateRecipientFiller(new TemplateTokens());
        $rendered = new RenderedTemplate(
            'For {{recipient.name}} <{{recipient.email}}>',
            '<p>Hi <span data-pl-var="recipient.first_name">First name</span>, at '
            . '<span data-pl-var="recipient.email">Recipient\'s address</span></p>',
        );

        $filled = $filler->fill($rendered, null, 'a&b@example.org');

        self::assertSame(
            '<p>Hi <span data-pl-var="recipient.first_name">First name</span>, at a&amp;b@example.org</p>',
            $filled->html,
            'escaped into the body: a value is text',
        );
        self::assertSame('For {{recipient.name}} <a&b@example.org>', $filled->subject);
        self::assertSame(['recipient.first_name', 'recipient.name'], $filler->open($filled));
        self::assertSame([], $filler->open($filler->fill($rendered, 'Dana Whitfield', 'dana@example.org')));
    }

    public function testAnOpenMarkerIsATokenInText(): void
    {
        self::assertSame(
            '<p>Hi {{recipient.first_name}},</p>',
            new TemplateRecipientFiller(new TemplateTokens())->markersAsTokens(
                '<p>Hi <span data-pl-var="recipient.first_name">First name</span>,</p>',
            ),
        );
    }
}
