<?php

declare(strict_types=1);

namespace App\Tests\Support\Push;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Mail\Account;
use DateTimeImmutable;
use RuntimeException;

/**
 * A mail push manager that records what it was asked to revoke, and can be told
 * to fail the way a dead token does.
 *
 * ── Why a tagged service and not a hand-built object ─────────────────────────
 * The thing under test is DataResetter, which is `final readonly` and reaches
 * its push managers through two `final readonly` registries fed by tagged
 * iterators. None of that can be mocked, and none of it should be rewritten to
 * be mockable — the sealing is deliberate. So the stub joins the iterator the
 * same way ScriptedCalendarSyncDriver does, and for the same stated reason.
 *
 * ── Why the marker, and why it is not optional ───────────────────────────────
 * Drivers arrive as a tagged iterator and the first to claim a subject wins, so
 * this is registered at a priority above the real managers. A stub that claimed
 * every account would therefore intercept push for the whole test suite, which
 * would be a far worse bug than the one it was added to catch. It claims only
 * accounts whose address carries MARKER, which no other fixture uses.
 */
final class ScriptedPushManager implements PushSubscriptionManagerInterface
{
    public const string MARKER = '+scripted-push@';

    /**
     * What each account still looked like at the moment it was revoked.
     *
     * The refresh token is the assertion that matters: revocation has to happen
     * BEFORE the truncate, because afterwards there is no credential left to
     * call the provider with. Recording it here is how a test can prove the
     * ordering rather than trusting the source.
     *
     * @var list<array{email: string, hadToken: bool}>
     */
    public array $revoked = [];

    /** Set to make unsubscribe() fail the way an already-dead grant does. */
    public bool $failEveryRevocation = false;

    public function supports(Account $account): bool
    {
        return str_contains((string) $account->email, self::MARKER);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function messageKey(): string
    {
        return 'scripted';
    }

    public function subscribe(Account $account): bool
    {
        return true;
    }

    public function renew(Account $account): bool
    {
        return true;
    }

    public function needsRenewal(Account $account): bool
    {
        return false;
    }

    public function expiresAt(Account $account): ?DateTimeImmutable
    {
        return $account->gmailWatchExpiry;
    }

    public function health(Account $account): PushHealth
    {
        return PushHealth::Active;
    }

    public function unsubscribe(Account $account): void
    {
        $this->revoked[] = [
            'email'    => (string) $account->email,
            'hadToken' => null !== $account->oauthRefreshToken,
        ];

        if (true === $this->failEveryRevocation) {
            throw new RuntimeException('scripted: the provider refused the revocation');
        }
    }
}
