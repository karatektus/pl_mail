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
 *
 * THE IDENTITIES THAT ARE NOT IN THE LIST
 *
 * A catch-all domain (Account::$catchAllDomains) makes every address at it one
 * the account may send as, and there are as many of those as there are local
 * parts — Identity/get cannot enumerate them. They are published one at a time
 * instead, where a client meets one: Email/get's `replyFrom` names the address
 * that answers a message (forAddress()), and when that address has no alias
 * row it comes with an *address identity*, whose id is the address itself in
 * an encoding a JMAP Id may carry (ADDRESS_ID_PREFIX).
 *
 * Such an id is not a capability and carries no authority. addressFor()
 * decodes it and asks Account::maySendAs() again, at the moment of sending, so
 * an id a client made up for `ceo@somebody-else.example` resolves to nothing
 * exactly as an unknown number does, and one minted yesterday stops resolving
 * the moment the domain is taken off the account.
 *
 * The roads not taken, since each looks simpler:
 *
 *  - An identity `*@example.com` in Identity/get is what RFC 8621 §6 offers
 *    for this. Every app already installed would show it in its From picker
 *    as an address and submit with it.
 *  - Letting Email/set write `from` and submitting with no identityId needs
 *    the address checked in two places instead of one, and an app talking to
 *    an older server would have its `from` refused or ignored and its mail
 *    sent from the primary address with nothing said.
 */
final class IdentityResolver
{
    /**
     * What an address identity's id starts with; base64url of the lowercased
     * address follows. Alias and account ids are decimal, so the two id
     * spaces cannot meet, and base64url is the whole of the alphabet RFC 8620
     * §1.2 allows an Id.
     */
    private const string ADDRESS_ID_PREFIX = 'addr-';

    /** RFC 8620 §1.2: an Id is at most 255 octets. */
    private const int MAX_ID_LENGTH = 255;

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

        // Not in the list: an address identity, or nothing. The id is only a
        // spelling of an address, so the account is asked whether it may send
        // as that address — now, not when the id was handed out. Refusing is
        // still the answer for everything else, which is the property the
        // paragraph above is about.
        $address = $this->addressIn($identityId);

        if (null !== $address && true === $account->maySendAs($address)) {
            return $address;
        }

        return null;
    }

    /**
     * The identity an address is sent as, or null when the account may not
     * send as it at all.
     *
     * For Email/get's `replyFrom`, which has an address — ReplyFromResolver's
     * answer — and owes the client something it can put in its From picker and
     * submit with. An alias gives the identity Identity/get lists for it, id
     * and all, so the client finds it in the list it already holds. Anything
     * else Account::maySendAs() accepts is an address at a catch-all domain
     * and gets an address identity: the account's name, since there is no
     * alias row to carry another (Account::senderNameFor()), and whatever
     * SignatureProvider signs that address with, which is the account's
     * signature for the same reason.
     *
     * @return array<string,mixed>|null
     */
    public function forAddress(Account $account, ?string $address): ?array
    {
        $address = strtolower(trim((string) $address));

        if (false === $account->maySendAs($address)) {
            return null;
        }

        foreach ($this->identities($account) as $identity) {
            if (strtolower((string) $identity['email']) === $address) {
                return $identity;
            }
        }

        $id = self::ADDRESS_ID_PREFIX . rtrim(strtr(base64_encode($address), '+/', '-_'), '=');

        // An address too long to spell as an Id has no identity to offer, and
        // the client stays on the primary address. 255 octets of base64 is a
        // 187-character address, which is longer than a mailbox may be at most
        // providers; it is refused here so a client is never handed an id the
        // spec says it may reject.
        if (strlen($id) > self::MAX_ID_LENGTH) {
            return null;
        }

        $signature = $this->signatures->htmlFor($account, $address);

        return [
            'id' => $id,
            'name' => $account->senderNameFor($address) ?? '',
            'email' => $address,
            'replyTo' => null,
            'bcc' => null,
            'textSignature' => $this->signatures->toText($signature),
            'htmlSignature' => $signature ?? '',
            'mayDelete' => false,
        ];
    }

    /**
     * Which identity an address was sent as, for the objects that have to
     * report one back (EmailSubmission/get).
     *
     * Falls back to the first identity when the address matches none of them —
     * mail sent from an alias that has since been removed still has to name an
     * identity, and the primary is the least wrong answer available.
     *
     * Mail sent from a catch-all address names its address identity, which is
     * the id the client submitted it with; reporting the primary there would
     * tell a client its From had been swapped when it had not.
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

        if (true === $account->catchesAddress($wanted)) {
            $catchAll = $this->forAddress($account, $wanted);

            if (null !== $catchAll) {
                return (string) $catchAll['id'];
            }
        }

        return (string) $identities[0]['id'];
    }

    /**
     * The address an address identity's id spells, or null when the id is not
     * one. Says nothing about whether the account may send as it.
     */
    private function addressIn(string $identityId): ?string
    {
        if (false === str_starts_with($identityId, self::ADDRESS_ID_PREFIX)) {
            return null;
        }

        $decoded = base64_decode(strtr(substr($identityId, strlen(self::ADDRESS_ID_PREFIX)), '-_', '+/'), true);

        if (false === $decoded || '' === $decoded) {
            return null;
        }

        return strtolower(trim($decoded));
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
