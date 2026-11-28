<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Entity\Mail\TrustedImageSender;
use App\Service\Mail\MessageRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an image-allowlist entry may be, and what a pattern matches (#807).
 *
 * The three forms share one column, so the parsing is what keeps them apart:
 * an address, `*@domain`, or a pattern with `*` in the local part. A `*` in
 * the domain part is the one thing refused outright — it reads as "and every
 * subdomain", which no entry does.
 */
final class TrustedImageSenderEntryTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function inputs(): iterable
    {
        yield 'an address, lowercased'        => ['News@Example.COM', 'news@example.com'];
        yield 'a domain, as written'          => ['*@stripe.com', '*@stripe.com'];
        yield 'a domain, without the star'    => ['@Stripe.com', '*@stripe.com'];
        yield 'a domain, bare'                => ['stripe.com', '*@stripe.com'];
        yield 'a pattern'                     => ['invoice+*@stripe.com', 'invoice+*@stripe.com'];
        yield 'a pattern with two stars'      => ['a*b*@example.com', 'a*b*@example.com'];
        yield 'a star in the domain'          => ['foo@*.example.com', null];
        yield 'every subdomain'               => ['*.stripe.com', null];
        yield 'a domain with no dot'          => ['*@localhost', null];
        yield 'nothing'                       => ['  ', null];
        yield 'not an address'                => ['not-a-domain', null];
        yield 'a pattern no address could be' => ['a b*@example.com', null];
    }

    #[DataProvider('inputs')]
    public function testWhatAnInputBecomes(string $input, ?string $entry): void
    {
        self::assertSame($entry, TrustedImageSender::entryFor($input));
    }

    public function testTheFormIsReadOffTheEntry(): void
    {
        $address          = new TrustedImageSender();
        $address->address = 'news@example.com';
        $domain           = new TrustedImageSender();
        $domain->address  = 'example.com';
        $pattern          = new TrustedImageSender();
        $pattern->address = 'invoice+*@stripe.com';

        self::assertSame([false, false], [$address->isDomain, $address->isPattern]);
        self::assertSame([true, false], [$domain->isDomain, $domain->isPattern]);
        self::assertSame([false, true], [$pattern->isDomain, $pattern->isPattern]);
        self::assertSame('*@example.com', $domain->address);
    }

    public function testAnEntryThatMeansNothingCannotBeStored(): void
    {
        $entry = new TrustedImageSender();

        $this->expectException(InvalidArgumentException::class);

        $entry->address = 'foo@*.example.com';
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function patternCases(): iterable
    {
        yield 'any run of characters'        => ['invoice+*@stripe.com', 'invoice+statements+acct_134dfh@stripe.com', true];
        yield 'including none'               => ['invoice+*@stripe.com', 'invoice+@stripe.com', true];
        yield 'the fixed part is required'   => ['invoice+*@stripe.com', 'invoice@stripe.com', false];
        yield 'the domain is exact'          => ['invoice+*@stripe.com', 'invoice+x@mail.stripe.com', false];
        yield 'nor a look-alike domain'      => ['invoice+*@stripe.com', 'invoice+x@stripe.com.evil.test', false];
        yield 'a dot is a dot'               => ['foo.*@bar.de', 'fooX@bar.de', false];
        yield 'a dot is a dot, matched'      => ['foo.*@bar.de', 'foo.baz@bar.de', true];
        yield 'and the literal address too'  => ['foo.*@bar.de', 'foo.*@bar.de', true];
    }

    #[DataProvider('patternCases')]
    public function testWhatAPatternMatches(string $pattern, string $address, bool $matches): void
    {
        self::assertSame($matches, TrustedImageSender::patternMatches($pattern, $address));
    }

    public function testTheBarOffersACompanyDomainButNotAProviderEveryoneIsAt(): void
    {
        self::assertSame('stripe.com', MessageRenderer::trustableDomain('invoice+x@Stripe.com'));
        self::assertNull(MessageRenderer::trustableDomain('somebody@gmail.com'));
        self::assertNull(MessageRenderer::trustableDomain(null));
        self::assertNull(MessageRenderer::trustableDomain('not an address'));
    }
}
