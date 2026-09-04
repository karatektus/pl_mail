<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Entity\Monitoring\CategoryReport;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;

/**
 * Turns one message and one objection into a row worth reading later.
 *
 * ITS OWN SERVICE BECAUSE OF WHAT IT HAS TO AGREE WITH. The whole value of a
 * report is that it records what the three answers WERE, and those come from
 * MessageCategorizer under the reader's own settings — the same call the
 * details panel makes to show them. Assembled in the controller, the panel and
 * the report could drift, and a report that disagrees with the popover it was
 * pressed in is worse than none: it would send somebody chasing a discrepancy
 * that exists only between two copies of one rule.
 *
 * The bulk-header names are the third thing that must agree, this time with
 * ClassifyMailHandler — that line IS what the model was shown, and a report
 * claiming different evidence would be a report about a decision nobody made.
 */
final readonly class CategoryReportRecorder
{
    /**
     * The headers that mean "sent to many".
     *
     * Deliberately the same list ClassifyMailHandler sends the model, and
     * deliberately duplicated rather than shared: that one describes a message
     * TO a model and this one describes it to a person, and the day they should
     * differ, they should be able to. What must not happen is one of them
     * changing silently, which is what the tests on both are for.
     *
     * @var list<string>
     */
    private const array BULK_HEADERS = ['list-unsubscribe', 'list-id', 'precedence'];

    public function __construct(
        private MessageCategorizer $categorizer,
        private ContactRepository  $contacts,
    ) {
    }

    public function record(User $user, Message $message, MessageCategory $shouldBe): CategoryReport
    {
        $from = mb_strtolower(trim((string) $message->fromAddress));

        // One lookup, handed to the one call that needs it — the same shape
        // MessageCategoryExtension uses so the two answers cannot differ.
        $correspondents = $this->contacts->isCorrespondent($user, $from) ? [$from => true] : [];

        // plMail's own cascade with neither Gmail nor the model in the way,
        // which is what "the rules" means everywhere else in this feature.
        $rules = $this->categorizer->explain($message, $correspondents, true, true);

        $report = new CategoryReport();

        $thread = $message->thread;

        // THE THREAD'S CATEGORY, because that is the one the tabs filter on and
        // therefore the only one that answers "which tab was it in". The
        // message's own is an input to it — resolved most-recent-wins across
        // the conversation, overridden outright by a pin — and the two are free
        // to differ on any thread with more than one message. Recording the
        // message's made this feature's first real report read `filed:updates`
        // about a conversation its owner was reading in Primary.
        $filed = $thread->category ?? $message->category ?? MessageCategory::Primary;

        $report->usr          = $user;
        $report->messageId    = (int) $message->id;
        $report->filed        = $filed;

        // Only when they disagree. Then the placement is coming from a sibling
        // message or a pin rather than from the message in front of the reader,
        // which is a different problem with a different fix.
        $report->filedMessage = $message->category !== $filed ? $message->category : null;
        $report->pinned       = null !== $thread?->categoryPinnedAt;
        $report->shouldBe     = $shouldBe;
        $report->gmail        = null === $message->gmailLabelIds
            ? null
            : MessageCategory::fromGmailLabels($message->gmailLabelIds);
        $report->rules        = $rules['category'];
        $report->rulesSignal  = $rules['signal'];
        $report->model        = $message->aiCategory;

        // Asked and answered / asked and got nothing / never asked. `model:-`
        // could not tell the last two apart, and only one of them is evidence
        // about the prompt.
        $report->aiAsked      = null !== $message->aiCategorisedAt;
        $report->source       = $user->categorySorting->source;
        $report->overrideProvider = $user->categorySorting->overrideProvider;

        // What the classifier could actually read. HTML-only mail is handed
        // `(no plain text part)` and decided on headers and a subject alone —
        // worth knowing before treating a report as evidence about a prompt.
        $report->hasPlainText = '' !== trim((string) $message->bodyText);
        $report->bulkHeaders  = self::bulkHeaders($message);
        $report->listId       = self::listId($message);
        $report->fromAddress  = mb_substr((string) $message->fromAddress, 0, 320);
        $report->fromName     = mb_substr((string) $message->fromName, 0, 255);
        $report->subject      = (string) $message->subject;

        return $report;
    }

    /**
     * List-Id's value — the one bulk header whose value is worth keeping.
     *
     * The others are per-recipient tracking links. This is a stable name for
     * the mailing itself, identical for everyone who receives it, and the exact
     * string somebody would write a rule against.
     */
    private static function listId(Message $message): ?string
    {
        $headers = array_change_key_case($message->headers ?? [], CASE_LOWER);
        $listId  = trim((string) ($headers['list-id'] ?? ''));

        return '' === $listId ? null : mb_substr($listId, 0, 255);
    }

    /** Names only. The values are tracking links and say nothing extra. */
    private static function bulkHeaders(Message $message): string
    {
        $headers = array_change_key_case($message->headers ?? [], CASE_LOWER);

        return implode(', ', array_filter(
            self::BULK_HEADERS,
            static fn (string $name): bool => '' !== trim((string) ($headers[$name] ?? '')),
        ));
    }
}
