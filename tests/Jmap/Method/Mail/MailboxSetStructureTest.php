<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Jmap\Method\Mail\MailboxGetMethod;
use App\Jmap\Method\Mail\MailboxQueryMethod;
use App\Jmap\Method\Mail\MailboxSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;

/**
 * "Mailbox/set" — the shape of the label tree, and what it refuses to do to it.
 *
 * Colour lives in MailboxColorTest; this is the structure. Two rules do most of
 * the work and neither is the obvious choice:
 *
 * **Destroying a parent is refused, not cascaded.** A cascade would delete
 * mailboxes the client never named, in a single call, with no undo. Refusing
 * costs a client one depth-first walk and costs a user nothing.
 *
 * **Destroying a mailbox never destroys mail.** Detaching a label leaves every
 * message in place, so `onDestroyRemoveEmails` — which the spec defines as
 * "delete the messages too" — is refused rather than quietly ignored: a client
 * that asked for it and was told the destroy succeeded would believe the mail
 * was gone.
 */
final class MailboxSetStructureTest extends JmapTestCase
{
    private MailboxSetMethod $method;
    private MailboxGetMethod $get;
    private MailboxQueryMethod $query;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->method = $container->get(MailboxSetMethod::class);
        $this->get    = $container->get(MailboxGetMethod::class);
        $this->query  = $container->get(MailboxQueryMethod::class);
    }

    public function testItIsNamedMailboxSet(): void
    {
        self::assertSame('Mailbox/set', $this->method->name());
    }

    // ── create ────────────────────────────────────────────────────────────

    /**
     * The id a create returns is a binding id, and it has to be usable as a
     * Mailbox id immediately — that is the only id a client ever holds.
     */
    public function testCreateReturnsAnIdMailboxGetCanResolve(): void
    {
        $result = $this->handle(['create' => ['m1' => ['name' => 'Receipts']]]);

        $id = ((array) $result['created'])['m1']['id'];

        $fetched = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [$id]],
            $this->context(),
        );

        self::assertSame('Receipts', $fetched['list'][0]['name']);
        self::assertNull($fetched['list'][0]['role'], 'a created mailbox is custom, never a system role');
    }

    /**
     * Gmail encodes hierarchy in the name, so a slash in a leaf name would
     * silently create a nested label on the provider that plMail believes is
     * flat — two trees that disagree, from one accepted character.
     */
    public function testASlashInANameIsRefused(): void
    {
        $result = $this->handle(['create' => ['m1' => ['name' => 'Work/Receipts']]]);

        $error = ((array) $result['notCreated'])['m1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('parentId', $error['description']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableNames(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'not a string' => [['Receipts']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableNames')]
    public function testAMailboxNeedsAName(mixed $name): void
    {
        $result = $this->handle(['create' => ['m1' => ['name' => $name]]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['m1']['type']);
    }

    /**
     * Siblings must have distinct names — LabelResolver does find-or-create by
     * (parent, name), so a duplicate would make two labels one of them can
     * never reach again.
     */
    public function testTwoSiblingsCannotShareAName(): void
    {
        $this->handle(['create' => ['m1' => ['name' => 'Receipts']]]);

        $result = $this->handle(['create' => ['m2' => ['name' => 'Receipts']]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['m2']['type']);
    }

    /** The same name under a different parent is a different mailbox. */
    public function testTheSameNameIsFreeUnderADifferentParent(): void
    {
        $parent = $this->createMailbox('Work');
        $this->createMailbox('Receipts');

        $result = $this->handle([
            'create' => ['m1' => ['name' => 'Receipts', 'parentId' => $parent]],
        ]);

        self::assertArrayHasKey('m1', (array) $result['created']);
    }

    public function testAParentCreatedEarlierInTheRequestCanBeNamedByCreationId(): void
    {
        $result = $this->handle([
            'create' => [
                'parent' => ['name' => 'Work'],
                'child' => ['name' => 'Receipts', 'parentId' => '#parent'],
            ],
        ]);

        $created = (array) $result['created'];

        self::assertArrayHasKey('child', $created);

        $fetched = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [$created['child']['id']]],
            $this->context(),
        );

        self::assertSame($created['parent']['id'], $fetched['list'][0]['parentId']);
    }

    public function testAnUnknownParentIsRefused(): void
    {
        $result = $this->handle(['create' => ['m1' => ['name' => 'Orphan', 'parentId' => '999999']]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['m1']['type']);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function testRenamingACustomMailboxWorks(): void
    {
        $id = $this->createMailbox('Recipts');

        $result = $this->handle(['update' => [$id => ['name' => 'Receipts']]]);

        self::assertSame([$id => null], (array) $result['updated']);
        self::assertSame('Receipts', $this->mailboxById($id)['name']);
    }

    /**
     * System labels map onto provider built-ins like INBOX that cannot be
     * renamed through any API, and invariants elsewhere hang off the role.
     * Mailbox/get already advertises this through myRights.mayRename, so a
     * client that honours it never gets here.
     */
    public function testASystemMailboxCannotBeRenamed(): void
    {
        $inboxId = $this->mailboxIdFor(LabelRole::Inbox);

        $result = $this->handle(['update' => [$inboxId => ['name' => 'Not the inbox']]]);

        self::assertSame('forbidden', ((array) $result['notUpdated'])[$inboxId]['type']);
        self::assertSame('inbox', $this->mailboxById($inboxId)['role']);
    }

    /**
     * A mailbox inside itself would orphan the whole subtree from the root and
     * make the full-name accessor recurse until the stack runs out.
     */
    public function testAMailboxCannotBeMovedInsideItself(): void
    {
        $id = $this->createMailbox('Work');

        $result = $this->handle(['update' => [$id => ['parentId' => $id]]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[$id]['type']);
    }

    public function testAMailboxCannotBeMovedInsideItsOwnChild(): void
    {
        $parent = $this->createMailbox('Work');
        $child  = $this->createMailbox('Receipts', $parent);

        $result = $this->handle(['update' => [$parent => ['parentId' => $child]]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[$parent]['type']);
    }

    public function testAnUnknownPropertyIsRefusedForThatMailbox(): void
    {
        $id = $this->createMailbox('Work');

        $result = $this->handle(['update' => [$id => ['totalEmails' => 0]]]);

        self::assertSame('invalidPatch', ((array) $result['notUpdated'])[$id]['type']);
    }

    public function testSortOrderMustBeAnInteger(): void
    {
        $id = $this->createMailbox('Work');

        $result = $this->handle(['update' => [$id => ['sortOrder' => 'first']]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[$id]['type']);
    }

    public function testIsSubscribedMustBeABoolean(): void
    {
        $id = $this->createMailbox('Work');

        $result = $this->handle(['update' => [$id => ['isSubscribed' => 'yes']]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[$id]['type']);
    }

    public function testAnUnknownMailboxIsNotUpdated(): void
    {
        $result = $this->handle(['update' => ['999999' => ['name' => 'Nowhere']]]);

        self::assertSame('notFound', ((array) $result['notUpdated'])['999999']['type']);
    }

    // ── destroy ───────────────────────────────────────────────────────────

    /**
     * Refused with mailboxHasChild rather than cascaded, so a client deletes
     * depth-first and never has to warn about mailboxes it did not name.
     */
    public function testDestroyingAParentIsRefusedRatherThanCascaded(): void
    {
        $parent = $this->createMailbox('Work');
        $child  = $this->createMailbox('Receipts', $parent);

        $result = $this->handle(['destroy' => [$parent]]);

        self::assertSame('mailboxHasChild', ((array) $result['notDestroyed'])[$parent]['type']);
        self::assertSame([], $result['destroyed']);
        self::assertSame('Receipts', $this->mailboxById($child)['name'], 'the child was destroyed anyway');
    }

    public function testDestroyingTheChildFirstThenTheParentWorks(): void
    {
        $parent = $this->createMailbox('Work');
        $child  = $this->createMailbox('Receipts', $parent);

        self::assertSame([$child], $this->handle(['destroy' => [$child]])['destroyed']);
        self::assertSame([$parent], $this->handle(['destroy' => [$parent]])['destroyed']);
    }

    /**
     * Destroying a label that still holds mail is fine, and the mail keeps its
     * other mailboxes. That is what makes depth-first deletion safe to offer
     * without a "you will lose mail" warning.
     */
    public function testDestroyingAMailboxDetachesItFromMailWithoutLosingTheMail(): void
    {
        $message = $this->receivedMessage();
        $label = $this->customLabel('Receipts');
        $message->addLabel($label);
        $this->em->flush();

        $bindingId = (string) $this->labelResolver->binding($label, $this->account)->id;

        self::assertSame([$bindingId], $this->handle(['destroy' => [$bindingId]])['destroyed']);

        $this->em->refresh($message);

        self::assertSame(['inbox'], $this->rolesOn($message));
    }

    /**
     * plMail never deletes mail as a side effect of removing a label, so this
     * argument cannot be honoured. Refused rather than ignored: a client that
     * asked for it and read `destroyed` would believe the mail went with it.
     */
    public function testOnDestroyRemoveEmailsIsRefusedRatherThanIgnored(): void
    {
        $id = $this->createMailbox('Receipts');

        $result = $this->handle(['destroy' => [$id], 'onDestroyRemoveEmails' => true]);

        $error = ((array) $result['notDestroyed'])[$id];

        self::assertSame('forbidden', $error['type']);
        self::assertStringContainsString('Emails are always kept', $error['description']);
        self::assertSame('Receipts', $this->mailboxById($id)['name']);
    }

    public function testASystemMailboxCannotBeDestroyed(): void
    {
        $inboxId = $this->mailboxIdFor(LabelRole::Inbox);

        $result = $this->handle(['destroy' => [$inboxId]]);

        self::assertSame('forbidden', ((array) $result['notDestroyed'])[$inboxId]['type']);
    }

    public function testAnUnknownMailboxIsNotDestroyed(): void
    {
        $result = $this->handle(['destroy' => ['999999']]);

        self::assertSame('notFound', ((array) $result['notDestroyed'])['999999']['type']);
    }

    // ── state ─────────────────────────────────────────────────────────────

    public function testAStaleIfInStateFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['ifInState' => 'not-the-current-state', 'update' => []]);
    }

    public function testACreateAdvancesTheMailboxState(): void
    {
        $result = $this->handle(['create' => ['m1' => ['name' => 'Moves the state']]]);

        self::assertNotSame($result['oldState'], $result['newState']);
    }

    // ── what Mailbox/query then sees ──────────────────────────────────────

    /**
     * The filter is the only way a client asks "what is under this mailbox",
     * and it answers in the binding id space the create returned.
     */
    public function testMailboxQueryFiltersByParentId(): void
    {
        $parent = $this->createMailbox('Work');
        $child  = $this->createMailbox('Receipts', $parent);
        $this->createMailbox('Elsewhere');

        $result = $this->query->handle(
            ['accountId' => $this->accountId(), 'filter' => ['parentId' => $parent]],
            $this->context(),
        );

        self::assertSame([$child], $result['ids']);
    }

    public function testMailboxQueryFiltersByRole(): void
    {
        $inboxId = $this->mailboxIdFor(LabelRole::Inbox);
        $this->createMailbox('Receipts');

        $result = $this->query->handle(
            ['accountId' => $this->accountId(), 'filter' => ['role' => 'inbox']],
            $this->context(),
        );

        self::assertSame([$inboxId], $result['ids']);
    }

    /** A null parentId means "top level", not "no filter". */
    public function testAParentIdOfNullSelectsTheTopLevel(): void
    {
        $parent = $this->createMailbox('Work');
        $this->createMailbox('Receipts', $parent);

        $result = $this->query->handle(
            ['accountId' => $this->accountId(), 'filter' => ['parentId' => null]],
            $this->context(),
        );

        self::assertContains($parent, $result['ids']);
        self::assertCount(1, $result['ids']);
    }

    /**
     * There is no Mailbox/queryChanges, so this must stay false — a client
     * told changes are calculable would ask for a delta that has no method.
     */
    public function testMailboxQueryCannotCalculateChanges(): void
    {
        $result = $this->query->handle(['accountId' => $this->accountId()], $this->context());

        self::assertFalse($result['canCalculateChanges']);
    }

    /** total is opt-in, because counting is work nobody asked for. */
    public function testTotalIsOnlyReturnedWhenAskedFor(): void
    {
        $this->createMailbox('Receipts');

        $without = $this->query->handle(['accountId' => $this->accountId()], $this->context());
        $with = $this->query->handle(
            ['accountId' => $this->accountId(), 'calculateTotal' => true],
            $this->context(),
        );

        self::assertArrayNotHasKey('total', $without);
        self::assertSame(count($with['ids']), $with['total']);
    }

    public function testMailboxQueryRefusesANonObjectFilter(): void
    {
        $this->expectException(MethodException::class);

        $this->query->handle(
            ['accountId' => $this->accountId(), 'filter' => 'inbox'],
            $this->context(),
        );
    }

    public function testMailboxGetReportsUnknownIdsAsNotFound(): void
    {
        $id = $this->createMailbox('Receipts');

        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [$id, '999999']],
            $this->context(),
        );

        self::assertCount(1, $result['list']);
        self::assertSame(['999999'], $result['notFound']);
    }

    /** A property list narrows the object, but the id always comes back. */
    public function testMailboxGetAlwaysReturnsTheIdWhateverPropertiesAreAskedFor(): void
    {
        $id = $this->createMailbox('Receipts');

        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [$id], 'properties' => ['name']],
            $this->context(),
        );

        self::assertSame(['id' => $id, 'name' => 'Receipts'], $result['list'][0]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->method->handle(
            $arguments + ['accountId' => $this->accountId()],
            $this->context(),
        );
    }

    /** @return string the new mailbox's JMAP id */
    private function createMailbox(string $name, ?string $parentId = null): string
    {
        $properties = ['name' => $name];

        if (null !== $parentId) {
            $properties['parentId'] = $parentId;
        }

        $result = $this->handle(['create' => ['tmp' => $properties]]);

        self::assertArrayHasKey('tmp', (array) $result['created'], json_encode($result['notCreated']));

        return ((array) $result['created'])['tmp']['id'];
    }

    /**
     * @return array<string,mixed>
     */
    private function mailboxById(string $id): array
    {
        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [$id]],
            $this->context(),
        );

        self::assertCount(1, $result['list'], sprintf('Mailbox "%s" is gone', $id));

        return $result['list'][0];
    }
}
