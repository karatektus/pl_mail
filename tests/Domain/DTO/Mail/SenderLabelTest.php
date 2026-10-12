<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Mail;

use App\Domain\DTO\Mail\SenderLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a list row prints for a sender: the display name, and — beside it, never
 * folded into it — the domain the mail really comes from.
 *
 * The expected values are written out by hand from the inputs. The one
 * judgement in here is WHICH domain: the registrable one, so that
 * `paypal.com.evil.example` reads as `evil.example` and not as something that
 * starts with the brand it is imitating.
 */
final class SenderLabelTest extends TestCase
{
    /**
     * The whole point. A name is free text the sender chose; the domain is the
     * one half of the line they cannot choose.
     */
    public function testANamedSenderCarriesTheDomainOfTheirAddress(): void
    {
        $label = SenderLabel::of('PayPal', 'service@evil.example');

        self::assertSame('PayPal', $label->name);
        self::assertSame('evil.example', $label->domain);
    }

    /** The presence companion: a different address gives a different domain. */
    public function testTheDomainFollowsTheAddressNotTheName(): void
    {
        self::assertSame('paypal.com', SenderLabel::of('PayPal', 'service@paypal.com')->domain);
        self::assertSame('evil.example', SenderLabel::of('PayPal', 'service@evil.example')->domain);
    }

    #[DataProvider('domains')]
    public function testTheDomainIsTheRegistrableOne(string $address, string $expected): void
    {
        self::assertSame($expected, SenderLabel::of('Someone', $address)->domain);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function domains(): iterable
    {
        // The subdomain is the cheapest trick there is: put the brand first.
        yield 'a brand in the subdomain does not lead' => ['x@paypal.com.evil.example', 'evil.example'];
        yield 'a mailing subdomain drops away' => ['noreply@mail.hetzner.com', 'hetzner.com'];
        yield 'co.uk needs three labels' => ['x@news.bbc.co.uk', 'bbc.co.uk'];
        yield 'case is normalised' => ['X@HETZNER.COM', 'hetzner.com'];
        // Punycode stays punycode, which is the honest form of a lookalike.
        yield 'an IDN shows as its A-label' => ['x@xn--pypal-4ve.example', 'xn--pypal-4ve.example'];
    }

    /** A host the helper cannot reduce still has to be shown — as it is. */
    public function testAHostWithNoRegistrableDomainIsShownWhole(): void
    {
        self::assertSame('192.0.2.1', SenderLabel::of('Someone', 'x@192.0.2.1')->domain);
        self::assertSame('localhost', SenderLabel::of('Someone', 'x@localhost')->domain);
    }

    /** Without a name the label IS the address, which already says it all. */
    public function testASenderWithoutANameIsTheAddressAlone(): void
    {
        foreach ([null, '', '   '] as $name) {
            $label = SenderLabel::of($name, 'service@evil.example');

            self::assertSame('service@evil.example', $label->name);
            self::assertNull($label->domain);
        }
    }

    public function testANameThatIsTheAddressIsNotRepeated(): void
    {
        $label = SenderLabel::of('Service@Evil.example', 'service@evil.example');

        self::assertSame('service@evil.example', $label->name);
        self::assertNull($label->domain);
    }

    public function testNoAddressLeavesTheNameAlone(): void
    {
        $label = SenderLabel::of('Someone', null);

        self::assertSame('Someone', $label->name);
        self::assertNull($label->domain);
    }

    public function testAnAddressWithNoAtSignHasNoDomainToShow(): void
    {
        self::assertNull(SenderLabel::of('Someone', 'not-an-address')->domain);
    }

    public function testNothingAtAllIsAnEmptyLabel(): void
    {
        $label = SenderLabel::of(null, null);

        self::assertSame('', $label->name);
        self::assertNull($label->domain);
    }
}
