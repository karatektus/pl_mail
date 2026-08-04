<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Calendar\EventProposal;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\EventProposalRepository;
use App\Repository\Calendar\EventSourceLinkRepository;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Graph\GraphMessageBuilder;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Whether a message may offer a date, and the row that offer becomes.
 *
 * The detectors read; this decides. Everything about *when it is acceptable to
 * guess* lives here and nowhere else, because a second detector will arrive and
 * must not bring its own opinion about what counts as noise with it.
 *
 * Precision over recall throughout, and one rule explains why: the cost of the
 * two mistakes is not symmetric. A missed appointment is a card that did not
 * appear, which nobody sees and nobody resents. A card offering to put "Sale
 * ends Friday!" on somebody's calendar is the moment they decide the feature is
 * stupid and go looking for the switch. So every rule below refuses on doubt.
 *
 * The rules, and what each one is for:
 *
 *   Not a draft, and not mail without an owner. Nothing to propose to.
 *
 *   Primary only. MessageCategorizer has already sorted bulk, marketing and
 *   discussion-list mail out at ingest, from the same persisted headers a
 *   backfill sees, so this is a column read rather than a second set of rules
 *   free to disagree with the first. An uncategorised message — a row from
 *   before that column existed — is refused too, because "not yet classified"
 *   is not "classified as personal".
 *
 *   No list or bulk headers, even when the category says Primary. The
 *   correspondent override pulls anyone the user has ever mailed back into
 *   Primary, which is right for a tab and wrong here: a shop the user once
 *   wrote to still sends newsletters, and those newsletters name dates.
 *
 *   Addressed to the user. One of the account's own addresses has to appear in
 *   To or Cc. A date announced to a list is an announcement; a date sent to you
 *   is an arrangement. This is the single rule that removes most of what
 *   survives the ones above.
 *
 *   Nothing an extractor could read. A text/calendar part, the Graph meeting
 *   header, or schema.org markup means a real event is coming — the cascade
 *   already says invites and structured data win — and the extraction that
 *   produces it runs asynchronously, so waiting to see the event would be a
 *   race. Refusing on the signal rather than on the outcome is the same answer
 *   without the race, and the outcome is checked as well for the backfill case,
 *   where extraction has already run.
 *
 *   Nothing in the past, nothing beyond the horizon — both judged against the
 *   message's own date, never against now(), so that a backfill re-reading old
 *   mail reaches the same verdict it reached the day the mail arrived.
 *
 *   Nothing already refused. The dedup key goes through the same
 *   EventSuppression table extracted events use, so "not an event" survives
 *   every later run.
 *
 * Does not flush — it joins the caller's unit of work, like every other writer
 * in this feature.
 */
final readonly class EventProposer
{
    /**
     * How far ahead prose is allowed to point.
     *
     * A year. Appointments arranged in a sentence are days or weeks away;
     * beyond that a person books rather than writes. What is out there instead
     * is contract language, warranty periods and renewal dates — real dates,
     * correctly parsed, that nobody wants on a calendar. The bound is generous
     * on purpose: it is a sanity limit, not a policy about what people plan.
     */
    private const int HORIZON_DAYS = 365;

    /** A title is a line on a card, not the document somebody pasted into the subject. */
    private const int MAX_TITLE = 200;

    /**
     * Headers that mean the mail went to a crowd.
     *
     * The same signals MessageCategorizer uses for Forums and Promotions,
     * checked again rather than trusted through the category, because the
     * category has a correspondent override on top of it and that override is
     * exactly what lets a newsletter through.
     *
     * @var list<string>
     */
    private const array BULK_HEADERS = [
        'list-id',
        'list-post',
        'list-unsubscribe',
        'x-mailman-version',
        'x-google-group-id',
        'feedback-id',
        'x-csa-complaints',
    ];

    /**
     * Repeated Re:/Fwd:/AW:/WG: prefixes, including spaced and counted forms.
     *
     * MessageThreader has the same pattern and is deliberately not reused: its
     * normaliser lower-cases, because what it produces is a threading key. A
     * title keeps the capitalisation the sender wrote.
     */
    private const string REPLY_PREFIXES = '/^(\s*(re|aw|fwd?|wg|antw|antwort|tr|rif)\s*(\[\d+\])?\s*:\s*)+/i';

    /**
     * @param iterable<ProposalDetectorInterface> $detectors
     */
    public function __construct(
        private DateShapeGate              $gate,
        private EventProposalRepository    $proposals,
        private EventSuppressionRepository $suppressions,
        private EventSourceLinkRepository  $links,
        private UserTimezoneResolver       $timezones,
        private EntityManagerInterface     $em,
        private LoggerInterface            $logger,
        #[Autowire('%kernel.default_locale%')]
        private string                     $defaultLocale,
        #[AutowireIterator('app.proposal_detector')]
        private iterable                   $detectors,
    ) {
    }

    /**
     * @return EventProposal|null null is the ordinary answer: almost no message
     *                            is arranging anything
     */
    public function propose(Message $message): ?EventProposal
    {
        $user = $message->account->usr;

        if (false === $user instanceof User || true === $message->isDraft()) {
            return null;
        }

        if (false === $this->isPersonalMail($message, $user)) {
            return null;
        }

        if (true === $this->carriesRealEvent($message)) {
            return null;
        }

        if (true === $this->proposals->hasAnyFor($message)) {
            return null;
        }

        $anchor = $message->receivedAt ?? $message->sentAt;

        // No anchor is no feature: every relative form and both bounds are
        // measured from the message's own date, and a message that cannot say
        // when it arrived cannot be reasoned about at all.
        if (null === $anchor) {
            return null;
        }

        $text = $this->textOf($message);

        if (false === $this->gate->passes($text)) {
            return null;
        }

        $context = new ProposalContext(
            message:  $message,
            usr:      $user,
            anchor:   $anchor,
            zone:     $this->timezones->resolve($user),
            language: $this->languageOf($user),
            text:     $text,
        );

        foreach ($this->ordered() as $detector) {
            $found = $this->detect($detector, $context);

            if (null === $found) {
                continue;
            }

            if (false === $this->isPlausible($found->startsAt, $anchor)) {
                continue;
            }

            $dedupKey = $this->dedupKey($message, $found->startsAt);

            if (true === $this->suppressions->isSuppressed($user, $dedupKey)) {
                return null;
            }

            return $this->write($context, $detector, $found, $dedupKey);
        }

        return null;
    }

    /**
     * The key a refusal is remembered by.
     *
     * Public because dismissal has to arrive at the same string from the stored
     * row, and a formula written twice is a suppression that quietly stops
     * matching. It names this message and this instant: another mail proposing
     * the same meeting is a separate claim with its own sentence behind it, and
     * refusing one is not a statement about the other.
     *
     * The Message-Id rather than the row id where there is one, so the key
     * survives a mailbox being deleted and resynced.
     */
    public function dedupKey(Message $message, DateTimeImmutable $startsAt): string
    {
        $identity = null !== $message->messageId && '' !== $message->messageId
            ? $message->messageId
            : sprintf('row:%d', (int) $message->id);

        // Separated by a NUL, which occurs in neither part.
        return 'proposal:' . hash('sha256', implode("\0", [
            $identity,
            $startsAt->format('Y-m-d\TH:i:s'),
        ]));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function write(
        ProposalContext           $context,
        ProposalDetectorInterface $detector,
        DetectedDate              $found,
        string                    $dedupKey,
    ): EventProposal {
        $proposal                 = new EventProposal();
        $proposal->usr            = $context->usr;
        $proposal->message        = $context->message;
        $proposal->title          = $this->titleOf($context->message);
        $proposal->startsAt       = $found->startsAt;
        $proposal->endsAt         = $found->endsAt;
        $proposal->timeZone       = $context->zone->getName();
        $proposal->confidence     = $found->confidence;
        $proposal->sourceSentence = $found->sentence;
        $proposal->dedupKeyHash   = EventSuppressionRepository::hash($dedupKey);
        $proposal->detector       = $detector->name();

        $this->em->persist($proposal);

        return $proposal;
    }

    /**
     * One broken detector must not cost the message, exactly as
     * EventExtractionRunner guards its extractors. A detector that throws has
     * found nothing, which is also the ordinary answer.
     */
    private function detect(ProposalDetectorInterface $detector, ProposalContext $context): ?DetectedDate
    {
        try {
            return $detector->detect($context);
        } catch (\Throwable $e) {
            $this->logger->error('EventProposal: detector failed', [
                'detector'  => $detector::class,
                'messageId' => $context->message->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<ProposalDetectorInterface>
     */
    private function ordered(): array
    {
        $detectors = iterator_to_array($this->detectors, false);

        usort(
            $detectors,
            static fn (ProposalDetectorInterface $a, ProposalDetectorInterface $b): int
                => $b->priority() <=> $a->priority(),
        );

        return $detectors;
    }

    /** Mail from a person, to this person. See the class docblock for each rule. */
    private function isPersonalMail(Message $message, User $user): bool
    {
        if (MessageCategory::Primary !== $message->category) {
            return false;
        }

        $headers = $this->headers($message);

        foreach (self::BULK_HEADERS as $name) {
            if (true === array_key_exists($name, $headers)) {
                return false;
            }
        }

        if ('bulk' === mb_strtolower($headers['precedence'] ?? '')) {
            return false;
        }

        return $this->isAddressedTo($message);
    }

    /**
     * Whether one of the account's own addresses is a named recipient.
     *
     * To and Cc, never Bcc: a Bcc copy is what a bulk sender produces, and it
     * is also how a list that rewrites nothing delivers. The addresses come
     * from Account::$ownedAddresses, which already accounts for verified
     * aliases, so a mail to an alias is still mail to the user.
     */
    private function isAddressedTo(Message $message): bool
    {
        $owned = $message->account->ownedAddresses;

        if ([] === $owned) {
            return false;
        }

        foreach ([$message->toAddresses ?? [], $message->ccAddresses ?? []] as $recipients) {
            foreach ($recipients as $recipient) {
                if (true === in_array($this->address($recipient), $owned, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A recipient entry, reduced to a bare lower-cased address.
     *
     * Stored shapes differ by provider: a string, or a map carrying `address`
     * or `email` beside a display name. Reading only one of them is how a whole
     * provider's mail silently stops qualifying.
     */
    private function address(mixed $recipient): string
    {
        if (true === is_array($recipient)) {
            $recipient = $recipient['address'] ?? $recipient['email'] ?? '';
        }

        $recipient = mb_strtolower(trim((string) $recipient));

        if (1 === preg_match('/<([^>]+)>/', $recipient, $matches)) {
            return trim($matches[1]);
        }

        return $recipient;
    }

    /**
     * Whether an extractor has already spoken for this message, or is going to.
     *
     * Both halves matter and they answer at different times — see the class
     * docblock. The signals are the same three MessageRepository selects
     * extraction candidates by, so "the extractors will look at this" is one
     * definition rather than two that can drift.
     */
    private function carriesRealEvent(Message $message): bool
    {
        foreach ($message->messageParts as $part) {
            if (true === in_array(
                mb_strtolower(trim((string) $part->contentType)),
                ['text/calendar', 'application/ics'],
                true,
            )) {
                return true;
            }
        }

        $headers = $this->headers($message);

        if (true === array_key_exists(mb_strtolower(GraphMessageBuilder::MEETING_TYPE_HEADER), $headers)) {
            return true;
        }

        $html = $message->bodyHtml;

        if (null !== $html && false !== stripos($html, 'ld+json')) {
            return true;
        }

        return null !== $message->id && [] !== $this->links->findAppliedForMessage($message);
    }

    /** Not behind the message, not absurdly ahead of it. */
    private function isPlausible(DateTimeImmutable $startsAt, DateTimeImmutable $anchor): bool
    {
        if ($startsAt < $anchor) {
            return false;
        }

        return $startsAt <= $anchor->modify(sprintf('+%d days', self::HORIZON_DAYS));
    }

    /**
     * The body as text.
     *
     * bodyText where the sender sent one, and the sanitised HTML stripped of
     * its tags where they did not — bodyHtmlSafe rather than bodyHtml, because
     * the safe copy has already had scripts and style blocks removed and the
     * contents of a <style> element are not prose. Entities are decoded, since
     * "&nbsp;" between a date and a time would otherwise put them further apart
     * than the parser allows.
     */
    private function textOf(Message $message): string
    {
        $text = trim((string) $message->bodyText);

        if ('' === $text) {
            $html = (string) ($message->bodyHtmlSafe ?? $message->bodyHtml);
            $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)));
        }

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The subject, minus the reply prefixes, because prose rarely names itself.
     *
     * A message with no subject gets the empty string and the card falls back to
     * its own wording — inventing "Untitled" here would put the word on the
     * calendar the moment the proposal is accepted.
     */
    private function titleOf(Message $message): string
    {
        $subject = trim((string) $message->subject);
        $title   = preg_replace(self::REPLY_PREFIXES, '', $subject);

        return mb_substr(trim(null === $title ? $subject : $title), 0, self::MAX_TITLE);
    }

    /**
     * The user's own language, which is what reads a slashed date.
     *
     * The install default when they never chose one, and never anything derived
     * from the message: a German mail read by an American does not make 04/08
     * the fourth of August, because the person deciding what the card says is
     * the reader.
     */
    private function languageOf(User $user): string
    {
        $locale = trim((string) ($user->locale ?? $this->defaultLocale));

        return mb_strtolower(substr('' === $locale ? $this->defaultLocale : $locale, 0, 2));
    }

    /**
     * Headers with their names lower-cased, since they are persisted exactly as
     * the server sent them and casing varies by provider.
     *
     * @return array<string,string>
     */
    private function headers(Message $message): array
    {
        $headers = [];

        foreach ($message->headers ?? [] as $name => $value) {
            if (true === is_array($value)) {
                $value = implode(' ', array_map(strval(...), $value));
            }

            $headers[mb_strtolower(trim((string) $name))] = trim((string) $value);
        }

        return $headers;
    }
}
