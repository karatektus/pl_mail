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

        $report->usr         = $user;
        $report->messageId   = (int) $message->id;
        $report->filed       = $message->category ?? MessageCategory::Primary;
        $report->shouldBe    = $shouldBe;
        $report->gmail       = null === $message->gmailLabelIds
            ? null
            : MessageCategory::fromGmailLabels($message->gmailLabelIds);
        $report->rules       = $rules['category'];
        $report->rulesSignal = $rules['signal'];
        $report->model       = $message->aiCategory;
        $report->source      = $user->categorySorting->source;
        $report->bulkHeaders = self::bulkHeaders($message);
        $report->fromAddress = mb_substr((string) $message->fromAddress, 0, 320);
        $report->fromName    = mb_substr((string) $message->fromName, 0, 255);
        $report->subject     = (string) $message->subject;

        return $report;
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
