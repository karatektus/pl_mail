<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\Push\NotifyScope;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Service\Mail\InboxCategoryManager;
use App\Service\Mail\NotifyScopes;
use App\Tests\Jmap\JmapTestCase;
use DateTimeImmutable;

/**
 * Which notification switches one message falls under (#369) — the rule the
 * Android app applies in `notifyScopeKeys()`, which the server now applies to
 * the browsers. Each case names the tab the web would show the message in,
 * because that is what the switch on the page says.
 */
final class NotifyScopesTest extends JmapTestCase
{
    private NotifyScopes $scopes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scopes = self::getContainer()->get(NotifyScopes::class);
    }

    public function testInboxMailFallsUnderItsBuiltInTab(): void
    {
        $message           = $this->receivedMessage();
        $message->category = MessageCategory::Social;

        self::assertSame(['category:social'], $this->scopes->forMessage($message, inInbox: true));
    }

    /** A category the reader switched off is listed under Primary, and notifies as Primary. */
    public function testASwitchedOffCategoryIsPrimary(): void
    {
        $this->user->categorySorting->switchOff([MessageCategory::Forums]);

        $message           = $this->receivedMessage();
        $message->category = MessageCategory::Forums;

        self::assertSame([NotifyScope::PRIMARY], $this->scopes->forMessage($message, inInbox: true));
    }

    /** A filter's tab wins over the built-in category, as on the web. */
    public function testATabOfTheReadersOwnIsItsOwnScope(): void
    {
        $shipping = self::getContainer()->get(InboxCategoryManager::class)->create($this->user, 'Shipping');

        $message               = $this->receivedMessage();
        $message->category     = MessageCategory::Updates;
        $message->ruleCategory = $shipping;

        self::assertSame(['tab:' . $shipping->tabKey()], $this->scopes->forMessage($message, inInbox: true));
    }

    /**
     * Without tabs the Inbox is one list and Primary's; the Inbox label counts
     * too there, because the phone offers it a switch then.
     */
    public function testWithoutTabsInboxMailIsPrimaryAndTheInboxLabelCounts(): void
    {
        $this->user->categorySorting->tabs = false;

        $message           = $this->receivedMessage();
        $message->category = MessageCategory::Promotions;
        $inbox             = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);

        self::assertSame([NotifyScope::PRIMARY, 'label:' . $inbox->id], $this->scopes->forMessage($message, inInbox: true));
    }

    public function testALabelCountsAndASystemOneDoesNot(): void
    {
        $work = $this->label('Work');

        $message = $this->receivedMessage([LabelRole::Inbox, LabelRole::Archive]);
        $message->addLabel($work);

        self::assertSame(
            [NotifyScope::PRIMARY, 'label:' . $work->id],
            $this->scopes->forMessage($message, inInbox: true),
            'the tab and Work; not the Inbox, which the tabs stand for, and not Archive',
        );
        self::assertSame(['label:' . $work->id], $this->scopes->forMessage($message, inInbox: false), 'out of the Inbox: the label alone');
    }

    public function testHeldMailAndMailYouWroteFallUnderNothing(): void
    {
        $held                 = $this->receivedMessage();
        $held->categoryHeldAt = new DateTimeImmutable();

        self::assertSame([], $this->scopes->forMessage($held, inInbox: true), 'its tab is not known yet');

        $sent = $this->receivedMessage([LabelRole::Sent]);
        $sent->addLabel($this->label('Work'));

        self::assertSame([], $this->scopes->forMessage($sent, inInbox: false), 'a sent copy, however it is labelled');

        $fresh = $this->receivedMessage();

        self::assertSame([NotifyScope::PRIMARY], $this->scopes->forMessage($fresh, inInbox: true), 'presence: ordinary Inbox mail');
    }

    private function label(string $name): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }
}
