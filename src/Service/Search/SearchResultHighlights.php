<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\DTO\Mail\ThreadRowMessage;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\ThreadRows;
use Twig\Markup;

/**
 * The highlighted subject and the match-aware preview for one page of search
 * results.
 *
 * What it fixes: a result row used to show the opening hundred characters of
 * the newest message, which for most hits does not contain the search term at
 * all. The row said a conversation matched and then showed something else, so
 * the only way to find out why it was there was to open it — which is the
 * failure a ranked search exists to avoid. Gmail's answer, and now this one, is
 * a window of body text AROUND the match with every occurrence marked.
 *
 * WHY THIS IS NOT HUNG ON THE THREAD. MessageThread is a mapped entity shared
 * with every list in the application; "how this particular search found you" is
 * a property of the search and not of the conversation, and a thread hydrated
 * here goes into Doctrine's identity map carrying the answer to somebody else's
 * question for the rest of the request. Same reasoning, and the same shape of
 * answer, as the `semanticOnly` id list — see
 * MessageThreadRepository::searchPage().
 *
 * THE BRIDGE FROM THREADS TO MESSAGES. `ts_headline` runs over a MESSAGE: the
 * subject and text part it highlights are columns of `message`, and the search
 * vector it agrees with is generated from those same columns. A result page is
 * a list of THREADS and spans every account in a unified mailbox, so this walks
 * the rows the page is already holding — ThreadRows has fetched their
 * messages' ids by the time this runs, so the ids cost nothing — groups them by the
 * THREAD's account, and asks for one batch of headlines per account.
 *
 * The thread's account rather than each message's: a thread belongs to exactly
 * one account (that is what `uniq_message_thread_provider_key_account` means),
 * so the two agree, and reading `$message->account` on fifty rows is a lazy
 * load per row waiting to happen — the exact regression
 * ThreadListQueryBudgetTest was written to catch.
 *
 * ONE QUERY PER ACCOUNT PER PAGE, which for the common single-account mailbox
 * is one. A headline per row would be fifty and would blow that budget on its
 * own.
 *
 * A FRAGMENT OF `body_text`, WHERE THE ROW'S PREVIEW IS MADE OF `bodyHtmlSafe`,
 * and the two disagree in ways that are already written down: the text part is
 * the half of a mail most recipients never see, so for bulk senders it is
 * routinely a sentence saying the mail is only available in HTML, and for some
 * it is HTML pasted in as text. That is why MessageSnippet prefers the rendered
 * body, and none of it is undone here — because this class REPLACES the preview
 * only when the fragment actually contains a hit, and a hit in `body_text` is
 * the literal reason the row is in the list: `search_vector` is generated from
 * subject, from_name, from_address and `body_text`, so the words the search
 * matched on are in that column and nowhere else.
 *
 * So the junk cases sort themselves out rather than needing to be recognised.
 * "This email is only available in HTML" does not contain the search term, so
 * the headline has no marker, so the row keeps the preview it has today. A text
 * part full of markup that DOES contain the term shows that markup as visible,
 * escaped text — which is exactly what the row already does with such a message
 * elsewhere, and is the honest answer: the reader is being shown the text the
 * search matched, character for character. There is deliberately no heuristic
 * here that tries to tell a good text part from a bad one; the marker is the
 * whole test.
 */
final class SearchResultHighlights
{
    /**
     * How far back down a conversation a fragment is worth looking for.
     *
     * Not a correctness limit — it is a ceiling on `ts_headline`, which parses
     * a whole body per call, so an unbounded version costs one document per
     * message on the page rather than per row. Newest first, because the row
     * already previews the newest message and a fragment from six replies ago
     * explains the row less the further back it comes from. A thread longer
     * than this whose match is older simply keeps today's preview, which is
     * the behaviour every other list has.
     */
    private const int MAX_MESSAGES_PER_THREAD = 5;

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly SearchHighlighter $highlighter,
        private readonly ThreadRows $rows,
    ) {
    }

    /**
     * Highlights for the threads on one page, keyed by thread id.
     *
     * A thread appears in the map only when something was actually marked, so
     * a caller can treat "absent" and "nothing matched in this row" as the same
     * thing — which is what lets the shared row template fall through to the
     * behaviour every other list has.
     *
     * @param MessageThread[] $threads
     *
     * @return array<int, array{subject: ?Markup, snippet: ?Markup}>
     */
    public function forPage(array $threads, string $freeText): array
    {
        $freeText = trim($freeText);

        // An operator-only search — `from:alice`, `is:unread` — has no term to
        // mark. `ts_headline` would answer with the opening words of every body
        // on the page, which is the preview the row already has, bought with an
        // extra query. Nothing to highlight means no work at all.
        if ('' === $freeText || [] === $threads) {
            return [];
        }

        $candidates = $this->candidates($threads);

        if ([] === $candidates) {
            return [];
        }

        $headlines = [];
        $bodies    = [];

        foreach ($candidates as $accountId => $messageIds) {
            $rows = $this->messages->findSearchHeadlines(
                $accountId,
                $messageIds,
                $freeText,
                SearchHighlighter::HEADLINE_OPTIONS,
            );

            $unmarked = [];

            foreach ($rows as $row) {
                $headlines[(int) $row['id']] = $row;

                if (!$this->highlighter->isMarked($row['preview'])) {
                    $unmarked[] = (int) $row['id'];
                }
            }

            // Only the bodies `ts_headline` declined to mark, which the
            // fallback has to search itself. Usually none, and then no
            // statement is issued at all.
            foreach ($this->messages->findSearchTexts($accountId, $unmarked, $unmarked) as $id => $text) {
                $bodies[$id] = $text['body_text'];
            }
        }

        $highlights = [];

        foreach ($threads as $thread) {
            $id = $thread->id;

            if (null === $id) {
                continue;
            }

            $highlight = $this->forThread($thread, $headlines, $bodies, $freeText);

            if (null !== $highlight['subject'] || null !== $highlight['snippet']) {
                $highlights[$id] = $highlight;
            }
        }

        return $highlights;
    }

    /**
     * The message ids to ask about, grouped by account id.
     *
     * @param MessageThread[] $threads
     *
     * @return array<int, list<int>>
     */
    private function candidates(array $threads): array
    {
        $candidates = [];

        foreach ($threads as $thread) {
            $accountId = $thread->account?->id;

            if (null === $accountId || null === $thread->id) {
                continue;
            }

            foreach ($this->newestFirst($thread) as $message) {
                $candidates[$accountId][] = (int) $message->id;
            }
        }

        return $candidates;
    }

    /**
     * The messages of one thread that are worth a headline, newest first.
     *
     * The row messages are ordered OLDEST first — the association's
     * `#[ORM\OrderBy]` on receivedAt, which ThreadRows repeats — so newest
     * first is that reversed. Both loops in this class read the same list, or
     * the one that asks for headlines and the one that reads them back would
     * disagree about which messages exist.
     *
     * @return list<ThreadRowMessage>
     */
    private function newestFirst(MessageThread $thread): array
    {
        $messages = array_values(array_filter(
            array_reverse($this->rows->for($thread)->messages),
            // A thread can hold a message that has never been flushed — the
            // compose dock persists a draft into a live thread — and an id-less
            // one has nothing for `WHERE m.id IN (…)` to find.
            static fn ($message): bool => null !== $message->id,
        ));

        return array_slice($messages, 0, self::MAX_MESSAGES_PER_THREAD);
    }

    /**
     * Which of a thread's messages gets to explain the row.
     *
     * The two fields are decided independently, and that is deliberate: a
     * conversation can have the term in the subject of every message and in the
     * body of only the oldest, and highlighting the subject from the newest
     * while previewing the fragment from the one that actually carries it is
     * the answer that describes the row best.
     *
     * WHERE THE FALLBACK'S TEXT COMES FROM. When `ts_headline` marked
     * nothing, SearchHighlighter needs the raw field to search it itself. The
     * list no longer hydrates message bodies (see ThreadRows), so forPage()
     * fetches `body_text` for exactly those unmarked messages through
     * MessageRepository::findSearchTexts() — not by widening
     * findSearchHeadlines(), which would pull every candidate's body a second
     * time, marked or not.
     *
     * @param array<int, array{id: int|string, subject: mixed, preview: mixed}> $headlines
     * @param array<int, mixed>                                                 $bodies    message id => raw body_text
     *
     * @return array{subject: ?Markup, snippet: ?Markup}
     */
    private function forThread(MessageThread $thread, array $headlines, array $bodies, string $freeText): array
    {
        $subject = null;
        $snippet = null;

        foreach ($this->newestFirst($thread) as $message) {
            $row = $headlines[(int) $message->id] ?? null;

            if (null === $row) {
                continue;
            }

            $snippet ??= $this->highlighter->toMarkup(
                $this->highlighter->headlineOrFallback($row['preview'], $bodies[(int) $message->id] ?? null, $freeText),
            );
            $subject ??= $this->subject($thread, $row['subject'], $freeText);

            if (null !== $subject && null !== $snippet) {
                break;
            }
        }

        return ['subject' => $subject, 'snippet' => $snippet];
    }

    /**
     * A highlighted subject, but only when it is still the row's subject.
     *
     * `ts_headline` is a FRAGMENTING function even over a field as short as a
     * subject: it will happily return the middle twenty-four words of a long
     * one, and it is given the MESSAGE's subject while the row draws the
     * THREAD's — which differ the moment somebody replies and the conversation
     * keeps the original wording. Printing either would silently rewrite the
     * subject line rather than highlight it, and a subject the reader cannot
     * trust is worse than one that is not marked.
     *
     * So: the same string with markers in it, or nothing. `ShortWord=0` in the
     * options is what makes the common case pass this guard at all — see
     * SearchHighlighter::HEADLINE_OPTIONS.
     *
     * THE FALLBACK IS GIVEN THE THREAD'S SUBJECT, not the message's, and that
     * is what lets the guard keep doing its job unchanged. A subject the
     * fallback marked in place comes back as that same string plus sentinels,
     * so it passes; one it had to window — a subject longer than
     * WORDS_BEFORE + WORDS_AFTER, or one whose runs of whitespace it collapsed
     * — comes back different and is refused here exactly as a fragmented
     * `ts_headline` subject is. The guard is one test and it does not need to
     * know which of the two produced the string it is testing.
     */
    private function subject(MessageThread $thread, mixed $headline, string $freeText): ?Markup
    {
        if (null === $thread->subject || '' === $thread->subject) {
            return null;
        }

        $marked = $this->highlighter->headlineOrFallback($headline, $thread->subject, $freeText);

        if ($this->highlighter->withoutMarkers($marked) !== $thread->subject) {
            return null;
        }

        return $this->highlighter->toMarkup($marked);
    }
}
