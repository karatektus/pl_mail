<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;

/**
 * Which addresses a message from one sending address is Bcc'd to by default
 * (#692), and putting them on a draft.
 *
 * The habit this serves is old and common: every mail you send also goes to a
 * second mailbox of yours, as a backup and as proof it left. Other clients
 * call it "Always Bcc" and keep it per identity.
 *
 * SHAPED LIKE SignatureProvider, ON PURPOSE
 * ─────────────────────────────────────────
 * Same two levels — Account::SETTING_DEFAULT_BCC for the account,
 * Account::defaultBccAliasSetting($id) overriding it for one address — read
 * the same way: an alias key that is PRESENT is the answer even when it is an
 * empty list, and only an absent one falls through to the account. Same
 * callers, too: a new window, ReplyDraftBuilder's reply and forward, the From
 * switch in the browser (tokenMap()), and "Identity/get" publishing it as the
 * identity's `bcc`. A rule written down once cannot differ between the
 * browser and the phone.
 *
 * IN THE FIELD, NOT ADDED AT SEND
 * ───────────────────────────────
 * The addresses go into the draft's Bcc row when the window opens, where the
 * sender can see them and take one off for a single message. Adding them
 * silently at send time was the other option, and it has two faults: nobody
 * can leave the copy off just this once, and a mail whose sent copy shows a
 * Bcc the composer never showed is a mail the sender was not told the truth
 * about. It also means a draft reopened later keeps whatever the sender left
 * in the row — this class is asked when a window is BUILT, never when a
 * saved draft is loaded again.
 */
final readonly class DefaultBccProvider
{
    /**
     * Enough for a backup mailbox, an archive and a colleague. A setting that
     * copies every mail to dozens of addresses is a mailing list, and it
     * should be one.
     */
    public const int MAX_ADDRESSES = 10;

    /**
     * The addresses in force for one sending address, lowercased, never
     * containing the sending address itself.
     *
     * The address is matched against the account's aliases the way
     * SignatureProvider matches it, from the From selector's address string.
     *
     * @return list<string>
     */
    public function addressesFor(Account $account, ?string $address): array
    {
        $alias  = $this->aliasFor($account, $address);
        $stored = $account->getSetting(Account::SETTING_DEFAULT_BCC);

        if (null !== $alias && null !== $alias->id) {
            $override = $account->getSetting(Account::defaultBccAliasSetting((int) $alias->id));

            // Present-but-empty is an answer: this address Bccs nobody.
            if (null !== $override) {
                $stored = $override;
            }
        }

        $from = AddressHelper::email($address ?? $account->displayAddress ?? $account->email);

        // A copy to the address it is sent from is a second copy of the Sent
        // folder, and an account Bcc'ing itself from its own alias list is the
        // easy way to set that up by accident.
        return array_values(array_filter(
            self::normalize($stored),
            static fn (string $bcc): bool => $bcc !== $from,
        ));
    }

    /**
     * Put the default Bcc on a draft that is being built.
     *
     * Only addresses the draft does not already name, in To, Cc or Bcc: a
     * reply to the backup mailbox itself must not list it twice, and an
     * address already in To would get the mail twice.
     */
    public function applyTo(Message $draft, Account $account, ?string $from): void
    {
        $present = [];

        foreach ([$draft->toAddresses, $draft->ccAddresses, $draft->bccAddresses] as $group) {
            foreach ($group ?? [] as $entry) {
                $present[AddressHelper::email((string) ($entry['address'] ?? ''))] = true;
            }
        }

        $bcc = $draft->bccAddresses ?? [];

        foreach ($this->addressesFor($account, $from ?? $account->displayAddress) as $address) {
            if (true === isset($present[$address])) {
                continue;
            }

            $bcc[]             = ['name' => null, 'address' => $address];
            $present[$address] = true;
        }

        $draft->bccAddresses = [] === $bcc ? null : $bcc;
    }

    /**
     * Every sending address of every account, keyed by the From selector's
     * "accountId|address" token, mapped to its Bcc list — so the composer
     * swaps the Bcc on a From change without asking the server. Built over
     * the same tokens as SignatureProvider::tokenMap(), catch-all wildcard
     * included, so that every option that has a signature also has an answer
     * here.
     *
     * @param iterable<Account> $accounts
     *
     * @return array<string, list<string>>
     */
    public function tokenMap(iterable $accounts): array
    {
        $map = [];

        foreach ($accounts as $account) {
            if (false === $account->sendEnabled) {
                continue;
            }

            $addresses = [];

            foreach ($account->sendableAliases as $alias) {
                $addresses[] = $alias->address;
            }

            $addresses[] = $account->displayAddress ?? $account->email ?? '';

            foreach ($addresses as $address) {
                if ('' === $address) {
                    continue;
                }

                $map[$account->id . '|' . $address] = $this->addressesFor($account, $address);
            }

            // An extra name signs and copies like its address: the From
            // menu's line for it has a token of its own (EmailAlias::identityId()).
            foreach ($account->sendableAliases as $alias) {
                foreach ($alias->extraNames as $entry) {
                    $map[$alias->identityId($entry['key'])] = $this->addressesFor($account, $alias->address);
                }
            }

            if ([] !== $account->catchAllDomains) {
                $map[$account->id . '|*'] = self::normalize($account->getSetting(Account::SETTING_DEFAULT_BCC));
            }
        }

        return $map;
    }

    /**
     * Read what someone typed into the settings field.
     *
     * Commas, semicolons, spaces and line breaks all separate, because people
     * paste lists in every one of those shapes. "Name <address>" is accepted
     * and kept as the address alone: the name would only be shown to the
     * sender, in a field that shows the address anyway.
     *
     * @return array{addresses: list<string>, invalid: ?string, tooMany: bool}
     */
    public static function parse(string $raw): array
    {
        $addresses = [];

        foreach (AddressHelper::splitList(str_replace([';', "\n", "\r"], ',', $raw)) as $fragment) {
            // A bare list separated by spaces alone is still a list; a display
            // name with spaces in it comes with angle brackets and is left
            // whole.
            $pieces = str_contains($fragment, '<') ? [$fragment] : (preg_split('/\s+/', $fragment) ?: []);

            foreach ($pieces as $piece) {
                if ('' === trim($piece)) {
                    continue;
                }

                if (false === AddressHelper::isValidEmail($piece)) {
                    return ['addresses' => [], 'invalid' => trim($piece), 'tooMany' => false];
                }

                $addresses[AddressHelper::email($piece)] = true;
            }
        }

        $addresses = array_keys($addresses);

        return [
            'addresses' => array_map('strval', $addresses),
            'invalid'   => null,
            'tooMany'   => count($addresses) > self::MAX_ADDRESSES,
        ];
    }

    /**
     * A stored list, whatever shape the bag holds — a list written by this
     * version, a hand-edited jsonb value, a string from a future one — reduced
     * to valid, lowercased, distinct addresses. Nothing stored can make the
     * composer put a malformed address in a Bcc row.
     *
     * @return list<string>
     */
    public static function normalize(mixed $stored): array
    {
        if (true === is_string($stored)) {
            $stored = [$stored];
        }

        if (false === is_array($stored)) {
            return [];
        }

        $out = [];

        foreach ($stored as $value) {
            if (false === is_string($value) || false === AddressHelper::isValidEmail($value)) {
                continue;
            }

            $out[AddressHelper::email($value)] = true;
        }

        return array_slice(array_map('strval', array_keys($out)), 0, self::MAX_ADDRESSES);
    }

    private function aliasFor(Account $account, ?string $address): ?EmailAlias
    {
        $wanted = AddressHelper::email($address);

        if ('' === $wanted) {
            return null;
        }

        foreach ($account->aliases as $alias) {
            if (AddressHelper::email($alias->address) === $wanted) {
                return $alias;
            }
        }

        return null;
    }
}
