<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Entity\Monitoring\CategoryReport;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Service\Mail\CategoryReportRecorder;
use App\Service\Mail\MessageCategorizer;
use PHPUnit\Framework\TestCase;

/**
 * What a report says happened, and whether it is about anything visible.
 *
 * PINNED BECAUSE THE FIRST REAL ONE WAS WRONG. It read `filed:updates` about a
 * conversation its owner was reading in Primary — true of `message.category`,
 * which is a column no tab is a filter over, and useless as evidence. The whole
 * value of this feature is that somebody can act on the line, so a line that
 * describes a state nobody can see is worse than no line.
 */
final class CategoryReportRecorderTest extends TestCase
{
    /**
     * The category a report names is the one the tabs sort by.
     *
     * A thread takes ONE category, from its newest message, so a conversation
     * sits in Primary while a message inside it is classified Updates. The
     * reader pressing Report is looking at the tab.
     */
    public function testFiledIsTheThreadsCategoryNotTheMessages(): void
    {
        $report = $this->record(MessageCategory::Primary, MessageCategory::Updates);

        self::assertSame(MessageCategory::Primary, $report->filed);
        self::assertSame(MessageCategory::Updates, $report->filedMessage);
        self::assertStringContainsString('filed:primary(msg:updates)', $report->asLine());
    }

    /** The ordinary case: nothing to say about a disagreement there isn't. */
    public function testMessageCategoryIsOmittedWhenItAgrees(): void
    {
        $report = $this->record(MessageCategory::Updates, MessageCategory::Updates);

        self::assertNull($report->filedMessage);
        self::assertStringContainsString('filed:updates should:', $report->asLine());
    }

    /**
     * Never asked and asked-for-nothing are different failures, and the line
     * has to tell them apart — only one of them is about the prompt.
     */
    public function testTheLineSaysWhenTheModelWasNeverAsked(): void
    {
        self::assertStringContainsString(
            'model:notasked',
            $this->record(MessageCategory::Primary, MessageCategory::Primary)->asLine(),
        );
    }

    private function record(MessageCategory $thread, MessageCategory $message): CategoryReport
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('isCorrespondent')->willReturn(false);

        $recorder = new CategoryReportRecorder(new MessageCategorizer(), $contacts);

        $mail                = new Message();
        $mail->fromAddress   = 'noreply@shop.test';
        $mail->fromName      = 'Shop';
        $mail->subject       = 'Your order';
        $mail->category      = $message;
        $mail->thread        = new MessageThread();
        $mail->thread->category = $thread;

        return $recorder->record(new User(), $mail, MessageCategory::Promotions);
    }
}
