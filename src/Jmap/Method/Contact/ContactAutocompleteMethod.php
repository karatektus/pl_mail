<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Entity\Mail\Contact;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Mail\ContactRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * "Contact/autocomplete": the addresses to offer while somebody types a
 * recipient, ranked so the useful ones come first.
 *
 * The one thing a composing client cannot do for itself. Suggestions have to be
 * ranked over the *whole* address book — a correspondent written to twice a
 * year outranks a mailing list seen once — and a phone that had just been set
 * up would otherwise offer nothing until it had synced enough mail to build a
 * local one. So the ranking is the server's, and this is the same address book
 * and the same query the web composer's autocomplete already runs
 * (ContactRepository::findForAutocomplete), because two rankings would make the
 * suggestion order depend on which device you were composing from.
 *
 * **Not RFC 8621, and not the JMAP Contacts draft**, which is why the method is
 * `autocomplete` rather than `query` and why Capability::CONTACTS is a vendor
 * URN. A `/query` returns ids for a `/get` to resolve; there is no
 * `Contact/get`, no id space, and nothing here a client may create or destroy.
 * Naming it `query` would promise that pattern and hand back objects instead of
 * ids, which is a worse lie than an unfamiliar verb.
 *
 * **No `id` on a suggestion, deliberately.** A `contact` row's primary key is
 * an implementation detail of the harvest — the row is deleted and re-inserted
 * by nothing, but it is also not addressable by any other method, so publishing
 * the key would invite a client to cache and re-fetch by something with no
 * getter. The address is the identity a client needs and the database agrees:
 * `uniq_contact_user_email` makes (user, email) unique, so `email` is a stable
 * key for dedupe.
 *
 * **`initials` is not returned** even though the web route returns it. It is
 * derived from `name` and `email` by a rule any client can apply, and it exists
 * on the HTML route only because the Turbo response feeds a chip renderer that
 * has nowhere to compute it.
 *
 * The accountId is resolved with the plain AccountResolver, so **every** one of
 * the user's accounts answers — a deliberate difference from calendars, which
 * are user-scoped in exactly the same way and yet are served from one account
 * only (see CalendarAccountResolver). That rule exists because a client keys
 * every object by (accountId, id) and would draw one calendar once per
 * connected account. Nothing here has an id and nothing is cached as an object,
 * so there is no such collision to prevent — while refusing every account but
 * one would fail a perfectly sensible call from whichever account the user
 * happens to be composing from. `primaryAccounts` still names one, for a client
 * that just wants somewhere to ask.
 */
final class ContactAutocompleteMethod implements JmapMethod
{
    /**
     * What a client gets when it says nothing. The same 8 the web composer
     * asks for (ContactRepository::findForAutocomplete's own default), because
     * a suggestion list that is a different length on the phone is the visible
     * half of two surfaces disagreeing.
     */
    public const int DEFAULT_LIMIT = 8;

    /**
     * The server-side cap, advertised in the Session so a client does not have
     * to discover it. Low on purpose: this is a type-ahead, and the query is
     * four case-folded LIKEs with no index behind them, so a client asking for
     * a thousand rows per keystroke is asking for a sequential scan per
     * keystroke.
     */
    public const int MAX_LIMIT = 50;

    /**
     * Everything this method reads. Anything else is refused by name rather
     * than ignored — a client that sent `filter`, `sort` or `position`
     * (reasonable guesses, since every other query-ish method here takes them)
     * would otherwise get a correct-looking unfiltered, unsorted first page and
     * no way to tell that none of it had been applied.
     *
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'query', 'limit'];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactRepository $contacts,
    ) {
    }

    public function name(): string
    {
        return 'Contact/autocomplete';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $query = $this->requireQuery($arguments['query'] ?? null);
        $limit = $this->resolveLimit($arguments['limit'] ?? null);

        $suggestions = [];

        foreach ($this->contacts->findForAutocomplete($context->user, $query, $limit) as $contact) {
            $suggestions[] = $this->toSuggestion($contact);
        }

        return [
            'accountId' => (string) $account->id,
            // Both echoed because both were normalised: the query is trimmed
            // before it reaches SQL, and the limit is capped. A client that
            // asked for 500 and got 50 can see that here rather than conclude
            // the address book holds 50 people.
            'query' => $query,
            'limit' => $limit,
            'list' => $suggestions,
        ];
    }

    /**
     * A suggestion is a JMAP EmailAddress ({name, email} — RFC 8621 §4.1.2,
     * the shape EmailMapper already emits for from/to/cc) with the ranking
     * signals hung off it. So a client can drop the object straight into an
     * `Email/set` create without translating field names, and the extra keys
     * are the ones it needs to explain or re-sort the list.
     *
     * @return array<string,mixed>
     */
    private function toSuggestion(Contact $contact): array
    {
        return [
            'name' => '' === (string) $contact->displayName ? null : $contact->displayName,
            'email' => (string) $contact->email,
            // The sort key: how many times this address has been seen in a
            // header, in either direction.
            'frequency' => $contact->frequency,
            // Recency, and whether the user has ever *written* to them as
            // opposed to merely heard from them. Neither is in the SQL order
            // today — see the README's deliberate limitations — so they are
            // returned rather than applied, which at least lets a client
            // distinguish a colleague from a newsletter of equal frequency.
            'lastSeenAt' => $this->utcOrNull($contact->lastSeenAt),
            'isCorrespondent' => $contact->isCorrespondent,
        ];
    }

    /**
     * Refused rather than answered. An absent or blank query matches every
     * contact the user has, and the LIKE would happily return the eight most
     * frequent — a plausible-looking list that no keystroke asked for, offered
     * while somebody addresses a message. The web route returns `[]` for the
     * same input, which is the right answer for a keystroke handler and the
     * wrong one for an API: "no matches" and "you sent no query" are different
     * facts and a client can act on only one of them.
     */
    private function requireQuery(mixed $query): string
    {
        if (false === is_string($query)) {
            throw new MethodException('invalidArguments', 'A string "query" is required.');
        }

        $query = trim($query);

        if ('' === $query) {
            throw new MethodException('invalidArguments', '"query" cannot be empty; send what the user has typed so far.');
        }

        return $query;
    }

    /**
     * Capped rather than refused, and the response says what was used. RFC 8620
     * §5.5 gives a server the limit as a maximum rather than an instruction,
     * and CalendarEvent/query already reads it that way here.
     */
    private function resolveLimit(mixed $limit): int
    {
        if (null === $limit) {
            return self::DEFAULT_LIMIT;
        }

        if (false === is_int($limit) || $limit < 1) {
            throw new MethodException('invalidArguments', sprintf(
                '"limit" must be an integer of at least 1, or null for %d.',
                self::DEFAULT_LIMIT,
            ));
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function rejectUnknownArguments(array $arguments): void
    {
        foreach (array_keys($arguments) as $argument) {
            if (true === in_array((string) $argument, self::ACCEPTED_ARGUMENTS, true)) {
                continue;
            }

            throw new MethodException('invalidArguments', sprintf(
                '"%s" is not an argument of Contact/autocomplete. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }

    /**
     * The same UTC spelling EmailMapper uses for every date it emits, so a
     * client parses one format across the API.
     */
    private function utcOrNull(?DateTimeImmutable $date): ?string
    {
        if (null === $date) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
