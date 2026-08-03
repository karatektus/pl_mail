<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\OAuthAccountLinker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which address a completed OAuth handshake actually names.
 *
 * Getting this wrong does not fail — it creates a working account pointed at
 * the wrong address, whose sent mail then fails to match its own synced copies.
 * Microsoft is where it happens: the Azure resource owner merges the id_token
 * claims with the Graph /me response, and the two disagree for anyone whose
 * sign-in identity is not their mailbox.
 */
final class OAuthAccountLinkerTest extends KernelTestCase
{
    private OAuthAccountLinker $linker;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->linker = self::getContainer()->get(OAuthAccountLinker::class);
    }

    /**
     * The regression this ordering exists for. `email` is the sign-in identity;
     * `mail` is the SMTP address synced messages are addressed to. Preferring
     * `email` gives an account that never matches its own mail.
     */
    public function testTheGraphMailboxWinsOverTheSignInIdentity(): void
    {
        self::assertSame('katharina@company.test', $this->linker->mailboxAddress([
            'mail'  => 'katharina@company.test',
            'email' => 'kat.admin@company.onmicrosoft.test',
        ]));
    }

    /** Google has no `mail` key at all, so it falls through unchanged. */
    public function testGoogleFallsThroughToTheEmailClaim(): void
    {
        self::assertSame('someone@gmail.test', $this->linker->mailboxAddress([
            'email' => 'someone@gmail.test',
        ]));
    }

    /** Org accounts that expose no distinct mailbox address, and nothing else. */
    public function testThePrincipalNameIsTheLastResort(): void
    {
        self::assertSame('someone@corp.test', $this->linker->mailboxAddress([
            'userPrincipalName' => 'someone@corp.test',
        ]));
    }

    /**
     * A blank or non-string value must not be preferred over a usable key
     * further down the list — an empty `mail` is a provider quirk, not an
     * address.
     */
    public function testAnEmptyOrNonStringValueIsSkippedRatherThanAccepted(): void
    {
        self::assertSame('someone@corp.test', $this->linker->mailboxAddress([
            'mail'              => '',
            'email'             => null,
            'userPrincipalName' => 'someone@corp.test',
        ]));
    }

    /**
     * Null rather than a guess: the caller refuses the handshake, which is the
     * only honest outcome when the provider named no mailbox.
     */
    public function testAPayloadNamingNoMailboxYieldsNothing(): void
    {
        self::assertNull($this->linker->mailboxAddress(['id' => '1234', 'displayName' => 'Katharina']));
    }
}
