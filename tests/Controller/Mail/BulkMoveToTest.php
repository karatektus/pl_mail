<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "Move to" route decides what comes off; the request only says where the
 * person was standing.
 *
 * MoveToServiceTest pins the calculation. This pins that the route is the
 * calculation and nothing wider — that a body cannot name labels to strip,
 * cannot file mail somewhere the picker does not offer, and that the Undo the
 * answer carries is a token the server holds the meaning of.
 *
 * One request for one conversation or many: the reading pane posts here with a
 * single id, so there is no second route to keep in step with this one.
 */
final class BulkMoveToTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASelectionMovesOutOfTheInboxInOneRequest(): void
    {
        $target = $this->seedLabel('Receipts');
        $first  = $this->inboxThread('First');
        $second = $this->inboxThread('Second');

        $this->post('move-to', [
            'ids'     => [$first->id, $second->id],
            'labelId' => $target->id,
            'scope'   => 'inbox',
            'value'   => 'primary',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = (string) $this->client->getResponse()->getContent();

        // Both rows leave the list they were moved from, and the answer says
        // where they went — with a way back.
        self::assertStringContainsString(sprintf('action="remove" target="thread_%d"', $first->id), $body);
        self::assertStringContainsString(sprintf('action="remove" target="thread_%d"', $second->id), $body);
        self::assertStringContainsString('Receipts', $body);
        self::assertMatchesRegularExpression('#/status/undo/[a-f0-9]{32}#', $body);

        foreach ([$first, $second] as $thread) {
            self::assertSame(['Archive', 'Receipts'], $this->labelNames($thread));
        }
    }

    /**
     * The point of sending a view rather than a list. A body that names labels
     * to remove is not an instruction this route has: the tag survives, and
     * what came off is what the view says comes off.
     */
    public function testAClientCannotNameLabelsToRemove(): void
    {
        $target = $this->seedLabel('Receipts');
        $tag    = $this->seedLabel('Important');
        $thread = $this->inboxThread('Tagged', $tag);

        $this->post('move-to', [
            'ids'     => [$thread->id],
            'labelId' => $target->id,
            'scope'   => 'inbox',
            'remove'  => [$tag->id],
            'add'     => [$this->seedLabel('Smuggled')->id],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Archive', 'Important', 'Receipts'], $this->labelNames($thread));
    }

    public function testUndoPutsTheSelectionBackExactlyAsItWas(): void
    {
        $target = $this->seedLabel('Receipts');
        $tag    = $this->seedLabel('Important');
        $thread = $this->inboxThread('Tagged', $tag);
        $before = $this->labelNames($thread);

        $this->post('move-to', ['ids' => [$thread->id], 'labelId' => $target->id, 'scope' => 'inbox']);

        self::assertSame(1, preg_match('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent(), $found));

        $this->postTo($found[0], []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->labelNames($thread));

        // Single use: the same toast pressed again is told so, not obeyed.
        $this->postTo($found[0], []);

        self::assertStringContainsString(
            'no longer be undone',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * Not refused and not announced. The picker hides the entry; a caller that
     * posts it anyway gets the honest answer, which is that nothing happened.
     */
    public function testMovingToTheViewItIsInIsAnsweredAsNothing(): void
    {
        $thread = $this->inboxThread('Stays');

        $this->post('move-to', ['ids' => [$thread->id], 'labelId' => $this->inbox->id, 'scope' => 'inbox']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('plmail-bulk: 0', $body);
        self::assertStringNotContainsString('status/undo', $body, 'a move that did nothing offered to undo it');
        self::assertSame(['Inbox'], $this->labelNames($thread));
    }

    public function testMailCannotBeMovedToAPlaceThePickerDoesNotOffer(): void
    {
        $sent = $this->seedLabel('Sent', LabelRole::Sent);

        $this->post('move-to', ['ids' => [], 'labelId' => $sent->id, 'scope' => 'inbox']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Inbox, Spam and Trash are asked for by role, because their labels are
     * made lazily: this user has never deleted anything and has no Trash label
     * for the picker to carry an id for. The move makes it.
     */
    public function testASystemPlaceIsNamedByRoleAndNeedNotExistYet(): void
    {
        $thread = $this->inboxThread('Binned');

        $this->post('move-to', ['ids' => [$thread->id], 'role' => 'trash', 'scope' => 'inbox']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Trash'], $this->labelNames($thread));
    }

    /** Asking by name is not a way round what the picker offers. */
    public function testARoleThePickerDoesNotOfferIsRefused(): void
    {
        $this->post('move-to', ['ids' => [], 'role' => 'sent', 'scope' => 'inbox']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Indistinguishable from a label that is somebody else's, or the route is
     * an existence check over every label id on the server.
     */
    public function testAnUnknownTargetIsRefused(): void
    {
        $this->post('move-to', ['ids' => [], 'labelId' => 999_999_999, 'scope' => 'inbox']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The toolbar's label menu posts the whole selection here now, and a tick
     * removed has to take the label off — `attach` absent still means attach,
     * which is all a drop has ever sent.
     */
    public function testTheBulkLabelRouteCanTakeALabelOffAndOffersUndo(): void
    {
        $label  = $this->seedLabel('Receipts');
        $thread = $this->inboxThread('Labelled', $label);

        $this->post('label', ['ids' => [$thread->id], 'labelId' => $label->id, 'attach' => false]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertMatchesRegularExpression('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent());
        self::assertSame(['Inbox'], $this->labelNames($thread));
    }

    public function testArchivingOffersUndo(): void
    {
        // Both made before the first request: it clears the entity manager
        // on its way out, and a fixture built afterwards hangs off an account
        // the manager no longer knows.
        $bulk = $this->inboxThread('From the toolbar');
        $row  = $this->inboxThread('From the row');

        $this->post('archive', ['ids' => [$bulk->id]]);

        self::assertMatchesRegularExpression('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent());

        $this->postTo(sprintf('/status/thread/%d/archive', $row->id), []);

        self::assertMatchesRegularExpression('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent());
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * A conversation whose MESSAGE is in the Inbox. The shared fixture labels
     * the thread only, which is enough for a list to show it and not enough
     * here: labels live on messages, and those are what a move reads.
     */
    private function inboxThread(string $subject, Label ...$labels): MessageThread
    {
        $thread = $this->thread($subject);

        foreach ($thread->messages as $message) {
            $message->addLabel($this->inbox);

            foreach ($labels as $label) {
                $message->addLabel($label);
                $thread->addLabel($label);
            }
        }

        $this->em->flush();

        return $thread;
    }

    /**
     * Read back from the database rather than off the instance held here: the
     * request did its own work on its own copies.
     *
     * @return list<string>
     */
    private function labelNames(MessageThread $thread): array
    {
        $this->em->clear();

        $fresh = $this->em->find(MessageThread::class, $thread->id);

        self::assertNotNull($fresh);

        $names = [];

        foreach ($fresh->messages as $message) {
            foreach ($message->labels as $label) {
                $names[] = (string) $label->name;
            }
        }

        sort($names);

        return $names;
    }

    /** @param array<string, mixed> $body */
    private function post(string $action, array $body): void
    {
        $this->postTo('/status/bulk/' . $action, $body);
    }

    /** @param array<string, mixed> $body */
    private function postTo(string $url, array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
