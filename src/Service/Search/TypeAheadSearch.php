<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\DTO\Search\TypeAheadHit;
use App\Entity\User\User;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\Message\MessageTypeAheadRepository;
use App\Repository\Mail\MessageThread\MessageThreadRepository;

/**
 * The search that runs while you are still typing.
 *
 * This is NOT a cheaper spelling of MessageThreadRepository::search(). It
 * answers a different question, and the difference is the whole design:
 *
 *   Enter asks "what matches, all of it, ranked and paged".
 *   A keystroke asks "is any of it worth stopping to look at, in ten rows,
 *   before I finish the word".
 *
 * The second question has a budget the first does not — a suggestion that
 * arrives after the next keystroke has been thrown away by definition — so
 * this runs only the passes that are cheap enough to spend on every keystroke,
 * and gives up the ones that are not. What it gives up it gives up openly:
 * pressing Enter still runs the complete search, which is where the expensive
 * passes live.
 *
 * The costs below were measured on a 300,000-message mailbox and are what every
 * decision in here is arguing with:
 *
 *   search_vector @@ to_tsquery('term:*')     ~110ms (13ms for a rare term)
 *   search_vector @@ websearch_to_tsquery()   ~ 90ms
 *   subject / from_name / from_address ILIKE  microseconds, usually
 *   body_text ILIKE '%term%'                  3,100ms   ← never, not here
 *
 * The body pass is not omitted for tidiness. `%needle%` is lossy on trigrams,
 * so every candidate body has to be re-read and re-matched, and on a common
 * needle that is 131MB of text — three seconds, per keystroke, for rows the
 * reader has already typed past. It stays in the full search, where a person
 * has committed to waiting.
 */
final class TypeAheadSearch
{
    /**
     * Ten rows is the list; these are the rows the SQL fetches to fill it.
     *
     * More than ten, because two things thin the candidates out after the
     * database has ranked them: several messages in one conversation collapse
     * to one row, and deleted conversations are dropped. Four times over is
     * enough that both have to be unusually unlucky to leave the list short,
     * and short is survivable — this is a preview, not the answer.
     */
    private const int CANDIDATE_FACTOR = 4;

    public function __construct(
        private readonly MessageTypeAheadRepository $typeAhead,
        private readonly MessageThreadRepository    $messageThreadRepository,
        private readonly AccountRepository          $accountRepository,
        private readonly FreeTextCompiler           $freeText = new FreeTextCompiler(),
    ) {
    }

    /**
     * @return list<TypeAheadHit>
     */
    public function suggest(User $user, string $text, int $limit = 10): array
    {
        $free = $this->freeText->compile($text);

        if ('' === $free->websearch) {
            return [];
        }

        // Bound as VALUES, and this is the single most expensive line in the
        // file to get wrong. Left as `account_id IN (SELECT id FROM account
        // WHERE usr_id = …)` the planner cannot estimate how many accounts come
        // back, reaches for the account_id index, and on a single-user install
        // that selects everything: 2,326ms per branch with the GIN index
        // untouched, against 126ms when it is handed the ids. Measured, twice,
        // and reintroduced once in between.
        $accountIds = $this->accountRepository->findActiveIdsForUser($user);

        if ([] === $accountIds) {
            return [];
        }

        $candidates = $limit * self::CANDIDATE_FACTOR;

        $rows = $this->typeAhead->findCandidates(
            $accountIds,
            $free->prefix,
            $free->websearch,
            null !== $free->substring ? $this->freeText->likePattern($free->substring) : null,
            $candidates,
        );

        return $this->toHits($rows, $limit);
    }

    /**
     * Rows to conversations: one row per thread, deleted ones and spam
     * dropped. Spam joined the bin here for the reason full search gives
     * (MessageThreadRepository, "Spam stays in Spam", #304); the type-ahead
     * has no `in:` to ask for either, and the search page behind it does.
     *
     * The trash filter is a second query over the forty candidates rather than
     * a NOT EXISTS inside the search, because inside it the label lookup runs
     * once per matching MESSAGE — twenty thousand probes for a common word, to
     * decide the fate of ten rows. Out here it is one indexed read of a list
     * this long.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<TypeAheadHit>
     */
    private function toHits(array $rows, int $limit): array
    {
        if ([] === $rows) {
            return [];
        }

        $threadIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['thread_id'],
            $rows,
        )));

        $trashed = array_flip($this->messageThreadRepository->findSetAsideAmong($threadIds));

        $hits = [];
        $seen = [];

        foreach ($rows as $row) {
            $threadId = (int) $row['thread_id'];

            if (isset($seen[$threadId]) || isset($trashed[$threadId])) {
                continue;
            }

            $seen[$threadId] = true;

            $hits[] = new TypeAheadHit(
                threadId:    $threadId,
                subject:     null !== $row['subject'] ? (string) $row['subject'] : null,
                fromName:    null !== $row['from_name'] ? (string) $row['from_name'] : null,
                fromAddress: null !== $row['from_address'] ? (string) $row['from_address'] : null,
                receivedAt:  null !== $row['received_at']
                    ? new \DateTimeImmutable((string) $row['received_at'])
                    : null,
            );

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }
}
