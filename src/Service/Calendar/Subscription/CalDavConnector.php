<?php

declare(strict_types=1);

namespace App\Service\Calendar\Subscription;

use App\Domain\Enum\Account\AuthType;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Integration\IntegrationConnector;
use Symfony\Component\Form\FormInterface;

/**
 * Making a CalDAV connection, including the one decision that is not the
 * server's business.
 *
 * Everything about storing and probing a connection already lives in
 * IntegrationConnector, and this goes through it rather than around it — the
 * blank-means-unchanged rule and the "every save re-probes" rule are that
 * class's and must not exist twice. What is here is the part CalDAV has that no
 * file service does: **the credential may be one the user already gave plMail
 * for their mailbox.**
 *
 * That is offered because it is genuinely what a self-hosted user wants —
 * Nextcloud, a Synology box, a mail server with a DAV module all take the same
 * account — and it is a deliberate tick rather than a default for one reason
 * worth stating plainly: the address is typed by the user and is not checked
 * against anything. Reusing silently, or by default, would mean a stored mail
 * password being sent as HTTP Basic to whatever host was in the box, on a form
 * whose subject appears to be calendars. So the checkbox starts off, the help
 * text says most servers want an app-specific password instead, and nothing
 * reads a stored secret unless the box was ticked in the submitted form.
 *
 * The address the form opens on is a suggestion and nothing more. RFC 6764
 * bootstrapping — CalDavDiscovery, three round trips from `.well-known/caldav`
 * — is what turns a bare domain into a calendar home, so prefilling the domain
 * of the user's own mailbox is exactly the "try their mail account's domain
 * first" step, done where the user can see and overwrite it rather than as a
 * hidden probe whose failure they would have no way to interpret.
 */
final readonly class CalDavConnector
{
    public function __construct(
        private IntegrationConnector $connector,
        private AccountRepository    $accounts,
    ) {
    }

    /**
     * Save the connection and try it.
     *
     * @return string|null the failure message, or null when the server answered
     */
    public function connect(Integration $integration, FormInterface $form): ?string
    {
        // Before save(), which only overwrites the secret when the password
        // field is non-blank — and it is blank whenever the box is ticked.
        $this->borrowMailPassword($integration, $form);

        return $this->connector->save($integration, $form);
    }

    /**
     * Copy a mail account's stored password onto the connection, but only
     * because the user ticked the box asking for exactly that.
     *
     * Every step is a refusal rather than a fallback. A submitted account id
     * that is not this user's, an account with no stored password, an unticked
     * box — all of them mean "no borrowed credential", never "borrow a
     * different one", because the alternative to a clear refusal here is a
     * mail password leaving the account it belongs to by accident.
     *
     * Public, and separate from connect(), because it is the decision this
     * class exists to make: connect() is this plus IntegrationConnector, and
     * that second half probes the server over the network. Splitting them is
     * what lets the rule be tested without one.
     *
     * @return bool whether a stored password was taken
     */
    public function borrowMailPassword(Integration $integration, FormInterface $form): bool
    {
        if (false === $form->has('reuseMailPassword') || true !== $form->get('reuseMailPassword')->getData()) {
            return false;
        }

        if (false === $form->has('reuseAccount')) {
            return false;
        }

        $account = $form->get('reuseAccount')->getData();

        if (false === $account instanceof Account || $account->usr !== $integration->usr) {
            return false;
        }

        $password = $account->password;

        if (null === $password || '' === $password) {
            return false;
        }

        $integration->secret = $password;

        return true;
    }

    /**
     * The mail accounts whose stored password could be reused.
     *
     * OAuth accounts are absent and cannot be added: there is no password to
     * lend, only a bearer token scoped to one provider's API, which is not a
     * credential any CalDAV server would accept.
     *
     * @return list<Account>
     */
    public function lendingAccounts(User $user): array
    {
        return array_values(array_filter(
            $this->accounts->findForUserOrderedByName($user),
            static fn (Account $account): bool => AuthType::Password->value === $account->authType
                && null !== $account->password
                && '' !== $account->password,
        ));
    }

    /**
     * The address to open the form on: the domain of the user's own mailbox,
     * which is where a self-hosted CalDAV service usually is.
     *
     * Null when there is nothing to guess from, in which case the field opens
     * empty rather than on a made-up host.
     *
     * Three candidates per account rather than one, and the order matters.
     * $displayAddress is what the UI shows and is usually an address — but it
     * falls back to Account::$email, which is a free-text column holding
     * whatever the account form was given and is routinely a display name
     * ("E2E Mailbox", "Work"). $username is the value IMAP actually
     * authenticates with and is an address far more often. Taking the first
     * that parses is what stops the field opening on "https://" and nothing.
     */
    public function suggestedAddress(User $user): ?string
    {
        foreach ($this->accounts->findForUserOrderedByName($user) as $account) {
            foreach ([$account->displayAddress, $account->username, $account->email] as $candidate) {
                $domain = $this->domainOf($candidate);

                if (null !== $domain) {
                    return 'https://' . $domain;
                }
            }
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The host part of something that is actually an address.
     *
     * A dot is required as well as an at-sign: "root@localhost" is a valid
     * mailbox and "https://localhost" is not a server anybody's calendars are
     * on, so suggesting it would be worse than suggesting nothing.
     */
    private function domainOf(?string $candidate): ?string
    {
        if (null === $candidate || false === str_contains($candidate, '@')) {
            return null;
        }

        $domain = trim(substr((string) strrchr($candidate, '@'), 1));

        return '' === $domain || false === str_contains($domain, '.') ? null : $domain;
    }
}
