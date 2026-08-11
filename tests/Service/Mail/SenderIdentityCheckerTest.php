<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\SenderIdentityChecker;
use App\Service\Mail\SenderMismatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The trigger definition, executable.
 *
 * A phishing warning that fires on ordinary mail trains the reflex it exists to
 * interrupt, so the silent cases below matter at least as much as the loud
 * ones — every one of them is a real display name that a sloppier rule would
 * have shouted about.
 */
final class SenderIdentityCheckerTest extends TestCase
{
    private SenderIdentityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new SenderIdentityChecker();
    }

    /**
     * The message from the bug report: a Hetzner-branded invoice phish whose
     * real sender shares nothing with the brand it wears.
     */
    public function testTheReportedPhishIsNamed(): void
    {
        $mismatch = $this->checker->check(
            $this->message('Hetzner Online GmbH', 'support@ownkhalsick.com'),
        );

        self::assertNotNull($mismatch);
        self::assertSame(SenderMismatchKind::BrandInName, $mismatch->kind);
        // Quoted back in the sender's own casing — the reader is being asked to
        // compare it with what they remember seeing, not with a normalised form.
        self::assertSame('Hetzner', $mismatch->claimed);
        self::assertSame('ownkhalsick.com', $mismatch->actual);
    }

    #[DataProvider('fires')]
    public function testItFires(string $name, string $address, SenderMismatchKind $kind): void
    {
        $mismatch = $this->checker->check($this->message($name, $address));

        self::assertNotNull($mismatch, sprintf('expected a warning for "%s" <%s>', $name, $address));
        self::assertSame($kind, $mismatch->kind);
    }

    /**
     * @return iterable<string, array{string, string, SenderMismatchKind}>
     */
    public static function fires(): iterable
    {
        yield 'a domain in the name that is not the sending domain' => [
            'service@paypal.com', 'billing@sendgrid-bounce.example', SenderMismatchKind::DomainInName,
        ];

        yield 'a bare domain worn as a display name' => [
            'amazon.de', 'noreply@aws-billing.example', SenderMismatchKind::DomainInName,
        ];

        yield 'an organisation whose brand appears nowhere in its domain' => [
            'Hetzner Online GmbH', 'support@ownkhalsick.com', SenderMismatchKind::BrandInName,
        ];

        yield 'an English legal form works the same way' => [
            'Northwind Traders Ltd', 'billing@invoice-portal.example', SenderMismatchKind::BrandInName,
        ];
    }

    #[DataProvider('staysSilent')]
    public function testItStaysSilent(string $name, string $address): void
    {
        self::assertNull(
            $this->checker->check($this->message($name, $address)),
            sprintf('expected NO warning for "%s" <%s>', $name, $address),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function staysSilent(): iterable
    {
        // A person's name is never judged: Rule 2 needs a legal form, and
        // without one there is nothing here that claims to be an organisation.
        yield 'a person at a webmail provider' => ['Jane Cooper', 'jane.cooper@gmail.com'];
        yield 'a person at their own company' => ['Karl Mustermann', 'karl@mustermann-bau.de'];
        yield 'a one-word display name' => ['Support', 'help@some-saas.example'];

        // The brand IS in the domain — which is the ordinary case, and the one
        // a careless rule gets wrong.
        yield 'brand matches the domain' => ['Hetzner Online GmbH', 'noreply@hetzner.com'];
        yield 'a later word matches the domain' => ['Deutsche Bahn AG', 'noreply@bahn.de'];
        yield 'a domain-shaped name that matches' => ['Amazon.de', 'ship-confirm@amazon.de'];

        // Nothing to judge on: every word is generic, so Rule 2 declines rather
        // than guessing from vocabulary that names no brand.
        yield 'an organisation named only in generic words' => [
            'Customer Services GmbH', 'info@some-host.example',
        ];

        // A dot that is punctuation, not a domain.
        yield 'an abbreviated honorific' => ['Mr. Smith', 'smith@example.com'];

        // Nothing was claimed beyond the address itself.
        yield 'the display name is the address' => ['bob@example.com', 'bob@example.com'];
        yield 'no display name at all' => ['', 'bob@example.com'];
    }

    /**
     * A domain that cryptographically signed the message may put what it likes
     * in the display name — that is what signing it means. This is the one
     * header in the set that the sender cannot forge, because our own provider
     * wrote it.
     */
    public function testPassingDkimForTheSendingDomainSuppressesTheWarning(): void
    {
        $message = $this->message('Hetzner Online GmbH', 'support@ownkhalsick.com');
        $message->headers['Authentication-Results'] =
            'mx.example.test; spf=pass; dkim=pass header.d=ownkhalsick.com; dmarc=pass';

        self::assertNull($this->checker->check($message));
    }

    /**
     * DKIM passing for SOMEBODY ELSE'S domain suppresses nothing. A forwarder
     * or a bulk sender signing with its own domain says nothing about whether
     * the display name is honest — which is the exact hole a naive
     * "dkim=pass ⇒ trusted" check would open.
     */
    public function testDkimPassingForAnotherDomainDoesNotSuppress(): void
    {
        $message = $this->message('Hetzner Online GmbH', 'support@ownkhalsick.com');
        $message->headers['Authentication-Results'] =
            'mx.example.test; dkim=pass header.d=bulk-mailer.example';

        self::assertNotNull($this->checker->check($message));
    }

    public function testFailingDkimDoesNotSuppress(): void
    {
        $message = $this->message('Hetzner Online GmbH', 'support@ownkhalsick.com');
        $message->headers['Authentication-Results'] =
            'mx.example.test; dkim=fail header.d=ownkhalsick.com';

        self::assertNotNull($this->checker->check($message));
    }

    /**
     * Documented, deliberate, and tested so that nobody 'fixes' it by accident:
     * a lookalike domain containing the brand defeats Rule 2. Catching it would
     * mean scoring edit distances against a brand list, and every false
     * positive that bought would be spent on ordinary mail.
     */
    public function testALookalikeDomainContainingTheBrandIsAKnownMiss(): void
    {
        self::assertNull(
            $this->checker->check($this->message('PayPal Inc.', 'service@paypal-secure.example')),
        );
    }

    private function message(string $fromName, string $fromAddress): Message
    {
        $message = new Message();
        $message->fromName    = $fromName;
        $message->fromAddress = $fromAddress;
        $message->headers     = [];

        return $message;
    }
}
