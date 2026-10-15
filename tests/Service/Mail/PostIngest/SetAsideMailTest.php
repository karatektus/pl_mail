<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Service\Mail\PostIngest\RecentMailPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Spam and Trash are kept and not worked on.
 *
 * A Gmail import now takes in both, and so the pipeline that reads new mail
 * for its meaning would have read them too: a model categorising spam, a fake
 * delivery notice becoming a parcel card, a forged invitation put in the
 * calendar. The rule lives on the message (Message::isSetAside()) and the
 * steps ask it; these pin the answers they get.
 */
final class SetAsideMailTest extends TestCase
{
    public function testSpamAndTrashAreSetAsideAndTheRestIsNot(): void
    {
        self::assertTrue($this->filedIn(LabelRole::Spam)->isSetAside());
        self::assertTrue($this->filedIn(LabelRole::Trash)->isSetAside());
        self::assertFalse($this->filedIn(LabelRole::Inbox)->isSetAside());
        self::assertFalse($this->filedIn(LabelRole::Archive)->isSetAside());
        self::assertFalse($this->filedIn(null)->isSetAside(), 'a user label is not a bin');
    }

    /** What insights, event extraction and event proposals are handed. */
    public function testTheStepsThatReadMailForMeaningAreNotGivenSpamOrTrash(): void
    {
        $inbox = $this->filedIn(LabelRole::Inbox, id: 1);
        $spam  = $this->filedIn(LabelRole::Spam, id: 2);
        $trash = $this->filedIn(LabelRole::Trash, id: 3);

        $result = new PostIngestResult([$inbox, $spam, $trash], [], [], true);
        $policy = new RecentMailPolicy(30);

        self::assertSame([1], $policy->recentIds($result));

        // Pairing a read receipt with the mail it is about reads nothing for
        // meaning, and a receipt filtered into Spam is still that mail's.
        self::assertSame([1, 2, 3], $policy->recentIds($result, includeSetAside: true));
    }

    private function filedIn(?LabelRole $role, ?int $id = null): Message
    {
        $label       = new Label();
        $label->role = $role;
        $label->name = null === $role ? 'Receipts' : $role->value;

        $message             = new Message();
        $message->receivedAt = new DateTimeImmutable('-1 day');
        $message->addLabel($label);

        if (null !== $id) {
            new \ReflectionProperty(Message::class, 'id')->setValue($message, $id);
        }

        return $message;
    }
}
