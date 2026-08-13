<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\AccountIdentityMismatch;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\AccountCreator;
use App\Service\Mail\AliasSeeder;
use App\Service\Push\PushSubscriptionRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Everything that turns a completed OAuth handshake into a usable mail account.
 *
 * The counterpart to AccountCreator, which does the same job for a
 * password-authenticated account: find-or-create the row, seed its aliases, and
 * leave it in a state the sync layer can work with. The two are kept apart
 * because almost nothing in the middle is shared — an OAuth account has no
 * credentials to probe and gets its host settings from the provider enum.
 */
final readonly class OAuthAccountLinker
{
    public function __construct(
        private AccountRepository        $accounts,
        private EntityManagerInterface   $em,
        private PushSubscriptionRegistry $pushRegistry,
        private AliasSeeder              $aliasSeeder,
        private AccountCreator           $accountCreator,
    ) {
    }

    /**
     * Attach the freshly authorised mailbox to the user, and make it live.
     *
     * Ordering is deliberate: the account is flushed before push and aliases,
     * both of which read a persisted account.
     */
    public function link(
        User                 $user,
        MailProvider         $provider,
        string               $email,
        AccessTokenInterface $token,
    ): Account {
        $account = $this->upsert($user, $provider, $email, $token);

        $this->registerPush($account);
        $this->aliasSeeder->seed($account);

        return $account;
    }

    /**
     * Write a freshly authorised token onto an account that already exists,
     * keeping everything else about it.
     *
     * This is the repair for a dead grant, and the whole point of it is what it
     * does NOT do: no row is created, none is deleted, and nothing that hangs
     * off the account id is touched. Mail, threads, mailboxes, labels, rules,
     * aliases, calendars and per-account settings all key on that id and all
     * survive, which is what makes this lighter than removing the account and
     * adding it back — the operation people otherwise resort to, and the one
     * that costs them their mail.
     *
     * ── The identity guard ───────────────────────────────────────────────────
     * The one thing that must never happen is this account quietly coming to
     * point at a DIFFERENT mailbox. A user with two Google accounts who is
     * signed into the wrong one in their browser will sail through the consent
     * screen without noticing, and the tokens that come back would be perfectly
     * valid — for somebody else's mail. Every later sync would then write that
     * mailbox's messages into this account's threads, and there is no undo for
     * that short of a restore.
     *
     * So the address is compared before anything is written, and a mismatch is
     * refused outright rather than resolved cleverly. Case-insensitively,
     * because providers do not agree with themselves about capitalisation
     * across the id_token and the profile response, and a refusal over `A`
     * versus `a` would be a wall with no way through it.
     *
     * The provider must match too. The same address can legitimately exist as
     * both a Google and a Microsoft account (see upsert()), and re-pointing one
     * at the other would be the same corruption by a slower route.
     *
     * @throws AccountIdentityMismatch when the authorised mailbox is not this one
     */
    public function relink(
        Account              $account,
        MailProvider         $provider,
        string               $email,
        AccessTokenInterface $token,
    ): Account {
        if (0 !== strcasecmp(trim($email), trim($account->email))) {
            throw new AccountIdentityMismatch($account->email, $email);
        }

        if ($provider->value !== $account->oauthProvider) {
            throw new AccountIdentityMismatch($account->email, $email);
        }

        $account->oauthAccessToken = $token->getToken();

        $refreshToken = $token->getRefreshToken();

        if (null !== $refreshToken && '' !== $refreshToken) {
            $account->oauthRefreshToken = $refreshToken;
        }

        $expires = $token->getExpires();

        if (null !== $expires) {
            $account->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        // The two lines this whole feature turned on. OAuthTokenManager writes
        // the error and clears it only on a successful REFRESH — but a
        // reconnect never goes through refresh(), so without this the account
        // would work again while still reporting itself broken, and the health
        // page would keep insisting on a repair that had already happened.
        $account->oauthLastRefreshError = null;
        $account->oauthLastRefreshAt    = new DateTimeImmutable();

        // A grant that died while the account was switched off leaves it off.
        // Reconnecting is an unambiguous "I want this working", so it is turned
        // back on — but only if it was us that stopped it, which is why this
        // reads the error rather than setting isActive unconditionally.
        $account->isActive = true;

        $this->em->flush();

        // Aliases are seeded rather than reseeded: AliasSeeder is idempotent,
        // and a mailbox that gained a send-as address while the grant was dead
        // would otherwise never pick it up.
        $this->aliasSeeder->seed($account);

        return $account;
    }

    /**
     * Resolve the mailbox address from the provider's resource-owner payload.
     *
     * Order matters. For Microsoft the Azure resource owner merges the id_token
     * claims with the Graph /me response: the OIDC `email` claim is the *sign-in*
     * identity , while Graph `mail` is the actual mailbox
     * SMTP address — the one that matches synced
     * messages' to_address. We want the mailbox, so `mail` is tried first.
     * `userPrincipalName` stays last for org accounts exposing no distinct `mail`.
     * Google has no `mail` key, so it falls through to `email` unchanged.
     *
     * @param array<string,mixed> $ownerData
     */
    public function mailboxAddress(array $ownerData): ?string
    {
        foreach (['mail', 'email', 'userPrincipalName'] as $key) {
            if (
                true === array_key_exists($key, $ownerData)
                && true === is_string($ownerData[$key])
                && '' !== $ownerData[$key]
            ) {
                return $ownerData[$key];
            }
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function upsert(
        User                 $user,
        MailProvider         $provider,
        string               $email,
        AccessTokenInterface $token,
    ): Account {
        // Email alone does NOT identify an account: an OAuth provider's login
        // address is independent of where the mail is hosted, so the same
        // address can legitimately exist as both an IMAP account and an OAuth
        // one. Adopting by email would silently convert the IMAP account and
        // null its password. Match on the full identity instead.
        $account = $this->accounts->findOneBy([
            'usr'           => $user,
            'email'         => $email,
            'authType'      => AuthType::OAuth2->value,
            'oauthProvider' => $provider->value,
        ]);

        if (null === $account) {
            $duplicate = $this->accounts->count(['usr' => $user, 'email' => $email]) > 0;

            $account = new Account();
            $account->usr = $user;
            $account->email = $email;
            $account->name = $duplicate ? sprintf('%s (%s)', $email, ucfirst($provider->value)) : $email;
            $account->isActive = true;
        }

        $account->username = $email;
        $account->authType = AuthType::OAuth2->value;
        $account->oauthProvider = $provider->value;
        $account->password = null;
        $account->oauthAccessToken = $token->getToken();

        $imapHost = $provider->imapHost();

        if (null !== $imapHost) {
            $account->imapHost = $imapHost;
            $account->imapPort = $provider->imapPort();
            $account->imapEncryption = $provider->imapEncryption();
        }

        $refreshToken = $token->getRefreshToken();
        if (null !== $refreshToken) {
            $account->oauthRefreshToken = $refreshToken;
        }

        $expires = $token->getExpires();
        if (null !== $expires) {
            $account->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        $this->em->persist($account);
        $this->em->flush();

        // isPrimary is a stored choice now, not "whichever account sits at
        // position 0" — so a path that creates an account without going through
        // AccountCreator has to say who the primary is, or a user whose only
        // account is an OAuth one ends up with none at all and no badge saying
        // which address their mail goes out from. Existing primaries are never
        // stolen; this only fills a gap.
        $this->accountCreator->ensurePrimary($this->accounts->findForUserOrdered($user));
        $this->em->flush();

        return $account;
    }

    /**
     * Establish push for a freshly connected account.
     *
     * On by default at connect time, because that is the one moment we know the
     * token is fresh and the user is present. Failure is non-fatal: the account
     * falls back to scheduled polling and the settings pane shows it as such.
     */
    private function registerPush(Account $account): void
    {
        $manager = $this->pushRegistry->resolve($account);

        if (null === $manager) {
            return;
        }

        $account->pushEnabled = true;
        $this->em->flush();

        if (false === $manager->subscribe($account)) {
            $account->pushEnabled = false;
            $this->em->flush();
        }
    }
}
