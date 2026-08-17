<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Domain\Enum\Account\AuthType;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * The Session says when an account has stopped working.
 *
 * A dead OAuth grant is otherwise **invisible** to a JMAP client. The account stays in the session,
 * every method keeps answering, and no mail ever arrives again — so a phone shows an inbox that has
 * simply gone quiet, with nothing to explain it and nothing to press. The browser has had a clear
 * "reconnect this account" card since v0.0.34; this is the same fact, published where a client can
 * reach it.
 *
 * What is published is a **token and a severity**, never the rendered card. Titles, bodies and
 * repair buttons are translated Twig with Symfony routes attached, and a phone can use none of
 * that; what it can use is a name it maps to its own sentence and its own screen.
 */
final class AccountHealthCapabilityTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    /** A working account says so, and says nothing else. */
    public function testAHealthyAccountNeedsNoAttention(): void
    {
        $sync = $this->syncCapabilities($this->accountId());

        self::assertFalse($sync['needsAttention']);
        self::assertNull($sync['attentionKind']);
        self::assertNull($sync['attentionSeverity']);
    }

    /**
     * **The case this exists for.** A revoked Google grant, which is the single most common way a
     * self-hosted mailbox goes quiet, and the one a phone could previously do nothing about.
     */
    public function testARevokedGrantIsPublishedAsNeedingAttention(): void
    {
        $this->account->authType = AuthType::OAuth2->value;
        $this->account->oauthRefreshToken = null;
        $this->em->flush();

        $sync = $this->syncCapabilities($this->accountId());

        self::assertTrue($sync['needsAttention']);
        self::assertSame('account_reconnect', $sync['attentionKind']);
        self::assertSame('critical', $sync['attentionSeverity']);
    }

    /** A grant that refreshes but keeps failing is the same problem reported a different way. */
    public function testAFailingRefreshIsAlsoReported(): void
    {
        $this->account->authType = AuthType::OAuth2->value;
        $this->account->oauthRefreshToken = 'still-here';
        $this->account->oauthLastRefreshError = 'invalid_grant';
        $this->em->flush();

        self::assertTrue($this->syncCapabilities($this->accountId())['needsAttention']);
    }

    /**
     * A password account has no grant to lose, so the OAuth check must not answer for it — a client
     * told to "reconnect" an IMAP account has been sent somewhere that cannot help it.
     */
    public function testAPasswordAccountIsNeverAskedToReconnect(): void
    {
        $this->account->authType = AuthType::Password->value;
        $this->account->oauthRefreshToken = null;
        $this->em->flush();

        self::assertFalse($this->syncCapabilities($this->accountId())['needsAttention']);
    }

    /**
     * Per account, like everything else in this block. One mailbox needing attention must not make
     * a client distrust the two beside it that are working perfectly.
     */
    public function testEachAccountCarriesItsOwnVerdict(): void
    {
        $second = $this->secondAccount();

        $this->account->authType = AuthType::OAuth2->value;
        $this->account->oauthRefreshToken = null;
        $this->em->flush();

        self::assertTrue($this->syncCapabilities($this->accountId())['needsAttention']);
        self::assertFalse($this->syncCapabilities((string) $second->id)['needsAttention']);
    }

    /**
     * The backfill numbers still travel in the same block. They were here first, and a client
     * reading them must not have to care that something else moved in beside them.
     */
    public function testTheBackfillNumbersAreUndisturbed(): void
    {
        $sync = $this->syncCapabilities($this->accountId());

        self::assertArrayHasKey('backfillTarget', $sync);
        self::assertArrayHasKey('backfillPending', $sync);
    }

    /**
     * @return array<string,mixed>
     */
    private function syncCapabilities(string $accountId): array
    {
        $session = $this->sessions->build($this->user);

        return $session['accounts'][$accountId]['accountCapabilities'][Capability::SYNC];
    }
}
