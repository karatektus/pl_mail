<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use App\Service\Label\LabelStructurePropagator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What a structure change actually tells the provider.
 *
 * Everything interesting here is about a **rename of a label that is a
 * Microsoft master category**, because that is the case where getting the
 * message wrong did not merely lose the change — it made a second one.
 *
 * Exchange gives a master category a GUID, but its identity is its display
 * name: the string is what sits on each message, and it is the only handle
 * plMail is guaranteed to have. A category that came *from* Outlook was linked
 * to a label by name and nothing else, so a rename had no id to send, the
 * handler's rename branch was skipped for want of one, and control fell through
 * to the create branch below it — which created a category under the NEW name
 * and left the old one standing. The next inbound sync read that old one back
 * and put the original label beside the renamed one.
 *
 * So two fields have to be on the wire, and this pins both:
 *
 *   $previousFullName, without which a category with no recorded id cannot be
 *   found at all;
 *
 *   $categoryRemoteId, which is deliberately NOT the same field as $remoteId —
 *   that one carries a Gmail label id or an Exchange FOLDER id, and
 *   GraphLabelPolicy reads a folder id to mean "this label is a location".
 *
 * A recording bus rather than a mock: what is under test is the contents of the
 * envelope, and an expectation object would assert that the propagator calls
 * itself.
 */
final class LabelStructurePropagatorTest extends TestCase
{
    /** @var list<ApplyLabelStructureMessage> */
    private array $dispatched = [];

    private LabelStructurePropagator $propagator;
    private Account $account;

    protected function setUp(): void
    {
        $this->dispatched = [];

        // Closes over the sink rather than holding it as a property: a
        // by-reference property looks write-only to static analysis, which is
        // correct about this class and wrong about the test around it.
        $record = function (object $message): void {
            $this->dispatched[] = $message;
        };

        $bus = new class ($record) implements MessageBusInterface {
            public function __construct(private \Closure $record)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ($this->record)($message);

                return new Envelope($message);
            }
        };

        $this->propagator = new LabelStructurePropagator($bus);

        $this->account = new Account();
        $this->account->setSetting(Account::SETTING_LABEL_SYNC, true);
    }

    public function testARenameCarriesTheNameTheLabelUsedToHave(): void
    {
        $label = $this->boundLabel('Invoices');

        $label->name = 'Receipts';
        $this->propagator->renamed($label, 'Invoices');

        self::assertCount(1, $this->dispatched);
        self::assertSame(ApplyLabelStructureMessage::ACTION_RENAME, $this->dispatched[0]->action);
        self::assertSame('Receipts', $this->dispatched[0]->fullName);
        self::assertSame('Invoices', $this->dispatched[0]->previousFullName);
    }

    /**
     * The category id travels in its own field. Folded into $remoteId it would
     * reach the handler indistinguishable from a folder id, which is the
     * confusion that made a tag into a location.
     */
    public function testACategoryIdIsNotSentAsAFolderId(): void
    {
        $label   = $this->boundLabel('Invoices');
        $binding = $label->bindings->first();

        self::assertInstanceOf(LabelBinding::class, $binding);
        $binding->graphCategoryId = '6f2f1a3e-0000-4000-8000-000000000001';

        $this->propagator->renamed($label, 'Invoices');

        self::assertSame('6f2f1a3e-0000-4000-8000-000000000001', $this->dispatched[0]->categoryRemoteId);
        self::assertNull($this->dispatched[0]->remoteId, 'a category is not a folder and must not arrive as one');
    }

    /** A folder-backed label still sends its folder id, in the field for it. */
    public function testAFolderBackedLabelStillSendsItsFolderId(): void
    {
        $label   = $this->boundLabel('Invoices');
        $binding = $label->bindings->first();

        self::assertInstanceOf(LabelBinding::class, $binding);
        $binding->graphFolderId = 'AAMkAD-folder-id';

        $this->propagator->renamed($label, 'Invoices');

        self::assertSame('AAMkAD-folder-id', $this->dispatched[0]->remoteId);
        self::assertNull($this->dispatched[0]->categoryRemoteId);
    }

    /** Nothing is pushed for an account that has not opted in. */
    public function testAnAccountWithSyncOffHearsNothing(): void
    {
        $this->account->setSetting(Account::SETTING_LABEL_SYNC, false);

        $this->propagator->renamed($this->boundLabel('Invoices'), 'Invoices');

        self::assertSame([], $this->dispatched);
    }

    /**
     * Nor for a system label, which maps onto a provider built-in — INBOX,
     * SENT — that cannot be created, renamed or deleted through the API.
     * $isSystem is derived from the role, so giving it one is what makes it one.
     */
    public function testASystemLabelIsNeverPushed(): void
    {
        $label       = $this->boundLabel('Inbox');
        $label->role = LabelRole::Inbox;

        $this->propagator->renamed($label, 'Inbox');

        self::assertSame([], $this->dispatched);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function boundLabel(string $name): Label
    {
        $label       = new Label();
        $label->name = $name;

        $binding          = new LabelBinding();
        $binding->account = $this->account;

        $label->addBinding($binding);

        return $label;
    }
}
