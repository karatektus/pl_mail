<?php

declare(strict_types=1);

namespace App\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\InsightDraft;
use App\Service\Insight\InsightExtractorInterface;

/**
 * GitHub notification mail → one card per issue or pull request.
 *
 * GitHub sends five mails about one review cycle — assigned, mentioned,
 * pushed, approved, merged — and five cards would be four too many. The
 * dedupe key is therefore the THING, `owner/repo#number`, deliberately
 * without the action: the harvester upserts on that key, so the newest mail
 * in the series owns the card and "merged" replaces "review_requested"
 * instead of standing beside it.
 *
 * Everything is read from what GitHub actually puts on the wire: the
 * `[owner/repo]` subject prefix, the `(#123)` subject suffix, the
 * `x-github-reason` header, and the canonical thread URL in the body. A mail
 * that does not name one clear repo and number — CI failure mails, Dependabot
 * round-ups — yields nothing rather than a guess; see the interface doc on
 * why refusing beats inventing.
 *
 * happensAt stays null on every draft: a pull request has no occurrence on a
 * timeline, which is the very distinction InsightKind exists to keep.
 */
final readonly class GithubExtractor implements InsightExtractorInterface
{
    /**
     * The canonical link GitHub puts in every notification body. One shape
     * covers both families; the captures keep the repo and the number, and
     * whether it said /pull/ or /issues/ is re-checked against the number
     * later, so a footer link to an unrelated thread cannot decide the kind.
     */
    private const string URL = '~https://github\.com/([\w.-]+/[\w.-]+)/(?:pull|issues)/(\d+)~';

    public static function key(): string
    {
        return 'github';
    }

    /**
     * The brands set, because it is already loaded — the account form wears
     * fa-brands fa-google and fa-brands fa-microsoft today, so fa-github
     * costs nothing extra and looks like GitHub rather than like "some code".
     */
    public function icon(): string
    {
        return 'fa-brands fa-github';
    }

    public function priority(): int
    {
        return 120;
    }

    public function supports(Message $message): bool
    {
        $from = mb_strtolower(trim((string) $message->fromAddress));

        if (true === str_ends_with($from, '@github.com')) {
            return true;
        }

        // A corporate relay or a forward can rewrite the sender; the List-Id
        // GitHub stamps on every thread notification survives both.
        $listId = $this->header($message, 'list-id');

        return null !== $listId && true === str_contains(mb_strtolower($listId), 'github.com');
    }

    public function extract(Message $message): array
    {
        $subject = trim((string) $message->subject);
        $body = (string) $message->bodyText;

        preg_match_all(self::URL, $body, $links, PREG_SET_ORDER);

        $repo = $this->repo($subject, $links);
        [$number, $numberFromSubject] = $this->number($subject, $links);

        if (null === $repo || null === $number) {
            return [];
        }

        // A digest names many threads and its subject names none: when the
        // number had to come from the body, more than one distinct thread in
        // the links means there is no ONE thing to make a card of.
        if (false === $numberFromSubject && 1 < $this->distinctThreads($links)) {
            return [];
        }

        $kind = $this->kind($subject, $body, $number);

        return [new InsightDraft(
            kind: $kind,
            title: $this->title($subject, $repo, $number),
            dedupeKey: sprintf('%s#%d', $repo, $number),
            payload: [
                'repo' => $repo,
                'number' => $number,
                'action' => $this->action($message, $body, $number),
                'url' => sprintf(
                    'https://github.com/%s/%s/%d',
                    $repo,
                    InsightKind::GithubPullRequest === $kind ? 'pull' : 'issues',
                    $number,
                ),
                'author' => $this->author($message),
            ],
        )];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The subject prefix first — GitHub writes `[owner/repo]` on every thread
     * mail and no link-rewriting proxy touches it — then the first body URL.
     *
     * @param list<array<int, string>> $links
     */
    private function repo(string $subject, array $links): ?string
    {
        if (1 === preg_match('~^(?:re:\s*)*\[([\w.-]+/[\w.-]+)\]~i', $subject, $match)) {
            return $match[1];
        }

        return $links[0][1] ?? null;
    }

    /**
     * The thread number, and whether the subject itself named it. Subject
     * forms first for the same reason as repo(): `(#123)` is on every thread
     * mail, and a subject that names one number names exactly one.
     *
     * @param list<array<int, string>> $links
     *
     * @return array{0: ?int, 1: bool}
     */
    private function number(string $subject, array $links): array
    {
        if (1 === preg_match('~\(#(\d+)\)~', $subject, $match)) {
            return [(int) $match[1], true];
        }

        if (1 === preg_match('~\bPR\s*#(\d+)\b~i', $subject, $match)) {
            return [(int) $match[1], true];
        }

        if (true === isset($links[0][2])) {
            return [(int) $links[0][2], false];
        }

        return [null, false];
    }

    /**
     * How many different threads the body links to. `#issuecomment-…`
     * fragments and repeated footers collapse onto the same pair, so a normal
     * notification counts one however many times it links to itself.
     *
     * @param list<array<int, string>> $links
     */
    private function distinctThreads(array $links): int
    {
        $threads = [];

        foreach ($links as $link) {
            $threads[$link[1] . '#' . $link[2]] = true;
        }

        return count($threads);
    }

    /**
     * The body URL for THIS number decides; "PR #" in a subject is the human
     * form of the same statement. Everything else — /issues/N and the mail
     * that says neither — is filed as an issue, the reading that claims less:
     * a PR card wrongly labelled issue is a wording slip, an issue promoted
     * to PR points its url at a page that 404s.
     */
    private function kind(string $subject, string $body, int $number): InsightKind
    {
        if (1 === preg_match(sprintf('~/pull/%d\b~', $number), $body)
            || 1 === preg_match('~\bPR\s*#~i', $subject)
        ) {
            return InsightKind::GithubPullRequest;
        }

        return InsightKind::GithubIssue;
    }

    /**
     * The verdict beats the reason: x-github-reason says why YOU got the mail
     * ("subscribed", "mention"), while "Merged #482 into main" says what
     * HAPPENED to the thing, and the card is about the thing. Opening lines
     * only, because that is where GitHub's own merge/close mails state it —
     * a later comment merely QUOTING "Merged #482" must not flip the card.
     */
    private function action(Message $message, string $body, int $number): ?string
    {
        $opening = implode("\n", array_slice(preg_split('~\r?\n~', ltrim($body)) ?: [], 0, 5));

        if (1 === preg_match(sprintf('~\bMerged\s+#%d\b~', $number), $opening)) {
            return 'merged';
        }

        if (1 === preg_match(sprintf('~\bClosed\s+#%d\b~', $number), $opening)) {
            return 'closed';
        }

        return $this->header($message, 'x-github-reason');
    }

    /**
     * `<owner/repo> #<N> — <what the mail was about>`, with the furniture the
     * repo and number already state stripped off the subject rather than said
     * twice.
     */
    private function title(string $subject, string $repo, int $number): string
    {
        $cleaned = trim((string) preg_replace(
            [
                '~^(?:re:\s*)+~i',
                sprintf('~^\[%s\]\s*~i', preg_quote($repo, '~')),
                sprintf('~\s*\(#%d\)\s*$~', $number),
            ],
            '',
            $subject,
        ));

        return '' === $cleaned
            ? sprintf('%s #%d', $repo, $number)
            : sprintf('%s #%d — %s', $repo, $number, $cleaned);
    }

    /**
     * The from-name is the acting person on thread mail ("Ada Lovelace") and
     * the literal string "GitHub" on the platform's own — where naming an
     * author would credit the robot, so null instead.
     */
    private function author(Message $message): ?string
    {
        $name = trim((string) $message->fromName);

        return '' === $name || 'GitHub' === $name ? null : $name;
    }

    /**
     * First value of a header: the bag stores a string or a list depending on
     * whether the header repeated, and callers should not care which. Keys
     * are canonical lowercase-dash — HeaderNormalizer's doing.
     */
    private function header(Message $message, string $name): ?string
    {
        $value = $message->headers[$name] ?? null;

        if (true === is_array($value)) {
            $value = $value[0] ?? null;
        }

        return true === is_string($value) && '' !== $value ? $value : null;
    }
}
