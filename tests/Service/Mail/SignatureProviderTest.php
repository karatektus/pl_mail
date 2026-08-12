<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\SignatureProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Which signature an address signs with, and what a signature is allowed to be.
 *
 * Two things are worth pinning here and they are both about a distinction that
 * is easy to collapse by accident:
 *
 *  1. An alias with NO key inherits the account signature; an alias whose key
 *     holds the empty string signs with nothing. Storing '' for both would
 *     make an unsigned address on a signed account impossible, which is the
 *     exact case a personal alias on a work mailbox is.
 *  2. Stored signature HTML is not trusted. It is injected verbatim into every
 *     outgoing message, so a `<script>` that reaches storage would be a script
 *     in the composer of whoever writes the next mail.
 */
final class SignatureProviderTest extends TestCase
{
    private SignatureProvider $signatures;

    protected function setUp(): void
    {
        $this->signatures = new SignatureProvider(new MailBodySanitizer(
            self::createStub(UrlGeneratorInterface::class),
            new NullLogger(),
        ));
    }

    // ── the two levels ───────────────────────────────────────────────────────

    public function testAnAddressWithNoOpinionInheritsTheAccountSignature(): void
    {
        $account = $this->account();
        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada</p>');

        self::assertSame('<p>Ada</p>', $this->signatures->htmlFor($account, 'work@example.test'));
    }

    public function testAnAddressWithItsOwnSignatureOverridesTheAccountOne(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada, Acme Ltd</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>ada</p>');

        self::assertSame('<p>ada</p>', $this->signatures->htmlFor($account, 'personal@example.test'));
    }

    /**
     * The distinction the whole two-level scheme exists for. An empty string
     * stored against the alias is an ANSWER — this address signs with nothing —
     * and must not fall through to the account signature.
     */
    public function testAnAddressThatSaysEmptySignsWithNothingEvenOnASignedAccount(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada, Acme Ltd</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '');

        self::assertNull($this->signatures->htmlFor($account, 'personal@example.test'));
        self::assertSame('', $this->signatures->blockFor($account, 'personal@example.test'));
    }

    /**
     * ...and removing the key puts the account signature back, which is what
     * the settings panel's "use the account signature" box posts.
     */
    public function testUnsettingTheAliasKeyRestoresInheritance(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');
        $key     = Account::signatureAliasSetting((int) $alias->id);

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada, Acme Ltd</p>');
        $account->setSetting($key, '');

        self::assertNull($this->signatures->htmlFor($account, 'personal@example.test'));

        $account->unsetSetting($key);

        self::assertSame('<p>Ada, Acme Ltd</p>', $this->signatures->htmlFor($account, 'personal@example.test'));
    }

    public function testAnAccountWithNoSignatureAtAllSignsWithNothing(): void
    {
        self::assertNull($this->signatures->htmlFor($this->account(), 'work@example.test'));
        self::assertSame('', $this->signatures->blockFor($this->account(), 'work@example.test'));
    }

    // ── the marker ───────────────────────────────────────────────────────────

    public function testTheBlockCarriesTheMarkerTheComposerSwapsOn(): void
    {
        $account = $this->account();
        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada</p>');

        self::assertSame(
            '<div class="pl-signature" data-pl-signature><p>Ada</p></div>',
            $this->signatures->blockFor($account, 'work@example.test'),
        );
    }

    // ── sanitising ───────────────────────────────────────────────────────────

    /**
     * A signature is HTML that gets injected into every message the user
     * writes. Feed it a script and nothing executable may come back.
     */
    public function testAScriptDoesNotSurviveSanitising(): void
    {
        $clean = $this->signatures->sanitize('<p>Ada</p><script>alert(1)</script>');

        self::assertStringNotContainsString('script', $clean);
        self::assertStringContainsString('Ada', $clean);
    }

    public function testAnEventHandlerAttributeDoesNotSurviveSanitising(): void
    {
        $clean = $this->signatures->sanitize('<p onclick="alert(1)">Ada</p>');

        self::assertStringNotContainsString('onclick', $clean);
    }

    public function testAnIframeDoesNotSurviveSanitising(): void
    {
        self::assertStringNotContainsString(
            'iframe',
            $this->signatures->sanitize('<iframe src="https://evil.test"></iframe>Ada'),
        );
    }

    /**
     * The sanitiser drops class and data- attributes, which is why the
     * `data-pl-signature` wrapper is added AROUND the sanitised value and never
     * stored inside it — and why a signature can never smuggle in a `data-cid`
     * that InlineImageRewriter would mistake for one of its own inline images.
     */
    public function testASignatureCannotCarryADataCidOfItsOwn(): void
    {
        $clean = $this->signatures->sanitize('<img src="https://example.test/x.png" data-cid="forged@plmail">');

        self::assertStringNotContainsString('data-cid', $clean);
        self::assertStringContainsString('https://example.test/x.png', $clean);
    }

    public function testOrdinaryFormattingSurvivesSanitising(): void
    {
        $clean = $this->signatures->sanitize('<p><strong>Ada</strong><br><a href="https://acme.test">acme.test</a></p>');

        self::assertStringContainsString('<strong>Ada</strong>', $clean);
        self::assertStringContainsString('https://acme.test', $clean);
    }

    // ── the text rendering JMAP publishes ────────────────────────────────────

    public function testTheTextRenderingFlattensTheHtml(): void
    {
        self::assertSame(
            "Ada Lovelace\nAcme Ltd",
            $this->signatures->toText('<p>Ada Lovelace<br>Acme Ltd</p>'),
        );
    }

    // ── the map the compose window hands the browser ─────────────────────────

    public function testTheTokenMapIsKeyedTheWayTheFromSelectorIs(): void
    {
        $account = $this->account();
        $alias   = $this->alias($account, 'personal@example.test');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>ada</p>');

        $map = $this->signatures->tokenMap([$account]);

        self::assertSame(
            '<div class="pl-signature" data-pl-signature><p>ada</p></div>',
            $map['7|personal@example.test'] ?? null,
        );
        self::assertSame(
            '<div class="pl-signature" data-pl-signature><p>Ada</p></div>',
            $map['7|work@example.test'] ?? null,
        );
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function account(): Account
    {
        $account = new Account();

        $this->setId($account, 7);
        $account->email    = 'work@example.test';
        $account->isActive = true;

        $this->alias($account, 'work@example.test', EmailAliasStatus::Primary, 1);

        return $account;
    }

    private function alias(
        Account            $account,
        string             $address,
        EmailAliasStatus   $status = EmailAliasStatus::Active,
        int                $id = 2,
    ): EmailAlias {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, $status);

        $this->setId($alias, $id);
        $account->addAlias($alias);

        return $alias;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
