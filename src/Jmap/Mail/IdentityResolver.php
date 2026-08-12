<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Service\Mail\SignatureProvider;

/**
 * The addresses an account may send as, and what a client's identityId means.
 *
 * One list, read twice: "Identity/get" publishes it, and
 * "EmailSubmission/set" resolves the identityId a client picked from it back
 * to an address. Those two used to be one class and no class — the list lived
 * inside Identity/get and the submission ignored identityId altogether, so a
 * client offering the alias picker the server had just published sent every
 * mail as the account's own address and nothing said otherwise.
 *
 * Sharing it is what makes the two agree **by construction**: an id this class
 * did not publish cannot resolve here, and every id it publishes does. A
 * second copy of "which aliases may I send as" is the shape the bug had.
 *
 * The source of truth underneath is Account::$sendableAliases, the same
 * property the web composer's From dropdown is built from (ComposeType), so
 * the browser and an app offer the same choices. Accounts with no alias rows
 * yet fall back to a single synthetic identity for the account address itself,
 * which is what sendableAliases degrades to elsewhere.
 */
final class IdentityResolver
{
    public function __construct(
        private readonly SignatureProvider $signatures,
    ) {
    }

    /**
     * Every identity of this account, in the order Identity/get lists them
     * (Primary first, because sendableAliases sorts it that way).
     *
     * @return list<array<string,mixed>>
     */
    public function identities(Account $account): array
    {
        $aliases = $account->sendableAliases;

        if (0 === count($aliases)) {
            return [$this->fallbackIdentity($account)];
        }

        $identities = [];

        foreach ($aliases as $alias) {
            $identities[] = $this->fromAlias($account, $alias);
        }

        return $identities;
    }

    /**
     * The From address an identityId selects, or null when it selects nothing
     * this account may send as.
     *
     * Null rather than a fallback to the account address, deliberately: the
     * caller is about to send mail, and an id that does not resolve means the
     * client believes it is sending as an address the server never offered.
     * Substituting the account's own address there would send the mail anyway,
     * from an address the user did not pick, with no error to notice.
     */
    public function addressFor(Account $account, string $identityId): ?string
    {
        foreach ($this->identities($account) as $identity) {
            if ($identity['id'] === $identityId) {
                return (string) $identity['email'];
            }
        }

        return null;
    }

    /**
     * Which identity an address was sent as, for the objects that have to
     * report one back (EmailSubmission/get).
     *
     * Falls back to the first identity when the address matches none of them —
     * mail sent from an alias that has since been removed still has to name an
     * identity, and the primary is the least wrong answer available.
     */
    public function identityIdFor(Account $account, ?string $address): string
    {
        $identities = $this->identities($account);
        $wanted = AddressHelper::email($address);

        foreach ($identities as $identity) {
            if (AddressHelper::email((string) $identity['email']) === $wanted) {
                return (string) $identity['id'];
            }
        }

        return (string) $identities[0]['id'];
    }

    /**
     * @return array<string,mixed>
     */
    private function fromAlias(Account $account, EmailAlias $alias): array
    {
        // The same lookup the composer does, and deliberately so: a phone and
        // the browser must sign a mail from this address identically, and the
        // per-alias override falling back to the account signature is the
        // whole of that rule. textSignature is derived rather than stored —
        // there is one signature, in HTML, and two renderings of it.
        $signature = $this->signatures->htmlFor($account, $alias->address);

        return [
            'id' => (string) $alias->id,
            'name' => $alias->displayName ?? $account->name ?? '',
            'email' => $alias->address,
            'replyTo' => null,
            'bcc' => null,
            'textSignature' => $this->signatures->toText($signature),
            'htmlSignature' => $signature ?? '',
            // Only aliases the user added by hand are theirs to remove. Ones
            // discovered from the provider come back on the next sync, and the
            // primary address is what the account sends as.
            'mayDelete' => EmailAliasSource::Manual === $alias->source
                && EmailAliasStatus::Primary !== $alias->status,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackIdentity(Account $account): array
    {
        $signature = $this->signatures->htmlFor($account, $account->email);

        return [
            'id' => (string) $account->id,
            'name' => $account->name ?? '',
            'email' => (string) $account->email,
            'replyTo' => null,
            'bcc' => null,
            'textSignature' => $this->signatures->toText($signature),
            'htmlSignature' => $signature ?? '',
            'mayDelete' => false,
        ];
    }
}
