<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Label\Label\LabelRepository;
use App\Service\Mail\BinView;
use App\Service\Mail\DiscardedListView;
use App\Service\Mail\MailPlacement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A conversation only partly archived or in Spam (#807).
 *
 * A conversation's labels are its messages' together, so a sender's new mail
 * joining a conversation whose earlier mail was archived wore Archive beside
 * Inbox — and the reading pane hid Archive, Delete and Snooze from it while it
 * sat in the Inbox. With Inbox mail in it, it is an Inbox conversation now;
 * Spam still decides on Spam's own side, as the bin does on its.
 */
final class MailPlacementMixedTest extends TestCase
{
    public function testAConversationWithInboxMailIsNotArchivedByAnOlderMessage(): void
    {
        self::assertSame(MailPlacement::NORMAL, $this->placement()->of($this->thread(LabelRole::Inbox, LabelRole::Archive)));
    }

    public function testAConversationWhollyArchivedStillIs(): void
    {
        self::assertSame(MailPlacement::ARCHIVE, $this->placement()->of($this->thread(LabelRole::Archive, LabelRole::Sent)));
    }

    /** A single message is where it is. */
    public function testAnArchivedMessageIsArchivedWhateverItsConversation(): void
    {
        $message = new Message();
        $message->addLabel($this->label(LabelRole::Archive));

        self::assertSame(MailPlacement::ARCHIVE, $this->placement()->of($message));
    }

    public function testOneSpamMessageDoesNotMakeAnInboxConversationSpam(): void
    {
        self::assertSame(MailPlacement::NORMAL, $this->placement()->of($this->thread(LabelRole::Inbox, LabelRole::Spam)));
    }

    /** From the Spam list, the same conversation keeps its way out of Spam. */
    public function testSpamStillDecidesWhereSpamIsBeingLookedAt(): void
    {
        $thread = $this->thread(LabelRole::Inbox, LabelRole::Spam);

        self::assertSame(MailPlacement::SPAM, $this->placement(new Request(attributes: ['_route' => 'app_mail_spam']))->of($thread));
        self::assertSame(MailPlacement::SPAM, $this->placement(new Request(query: ['spam' => '1']))->of($thread));
    }

    public function testAConversationWhollyInSpamIsSpamEverywhere(): void
    {
        self::assertSame(MailPlacement::SPAM, $this->placement()->of($this->thread(LabelRole::Spam)));
    }

    /** Spam is still the more final of the two when both are on a conversation outside the Inbox. */
    public function testSpamBeatsArchiveOutsideTheInbox(): void
    {
        self::assertSame(MailPlacement::SPAM, $this->placement()->of($this->thread(LabelRole::Archive, LabelRole::Spam)));
    }

    /** The bin's own rule is unchanged by sharing its list check with Spam. */
    public function testTheBinSideIsStillReadFromItsFlag(): void
    {
        $lists = $this->lists(new Request(query: ['bin' => '1']));

        self::assertTrue(new BinView($lists)->isOpen());
        self::assertFalse($lists->isOpen(LabelRole::Spam));
    }

    private function placement(?Request $request = null): MailPlacement
    {
        $lists = $this->lists($request ?? new Request());

        return new MailPlacement(new BinView($lists), $lists);
    }

    private function lists(Request $request): DiscardedListView
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new DiscardedListView($stack, $this->createStub(LabelRepository::class));
    }

    private function thread(LabelRole ...$roles): MessageThread
    {
        $thread = new MessageThread();

        foreach ($roles as $role) {
            $thread->addLabel($this->label($role));
        }

        return $thread;
    }

    private function label(LabelRole $role): Label
    {
        $label       = new Label();
        $label->name = $role->value;
        $label->role = $role;

        return $label;
    }
}
