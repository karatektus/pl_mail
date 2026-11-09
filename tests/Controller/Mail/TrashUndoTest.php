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
 * Trashing one conversation offers Undo, as archiving does (#527).
 *
 * The reason is the row swipe: since #527 a swipe can be set to delete, and a
 * swipe commits on a full drag with no tap in front of it. The same route serves
 * the row's own delete button and the action sheet, so they gain the Undo too.
 *
 * The claims: the trash answer carries an Undo whose token, posted back, puts the
 * conversation back in the Inbox and out of the bin; and restore, which shares
 * the stream template, still answers without one.
 */
final class TrashUndoTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private const string UNDO_URL = '#/status/undo/[a-f0-9]{32}#';

    private KernelBrowser $client;
    private Connection $connection;
    private Label $trash;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->trash   = $this->seedLabel('Trash', LabelRole::Trash);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTrashingAConversationOffersAnUndoThatPutsItBack(): void
    {
        $thread = $this->thread('Swiped by mistake');

        // The fixture labels the thread only; mail in the Inbox wears it on each
        // message too, and that is what the Undo has to put back.
        foreach ($thread->messages as $message) {
            $message->addLabel($this->inbox);
        }

        $this->em->flush();

        self::assertTrue($this->wears($thread, LabelRole::Inbox), 'baseline: in the Inbox before the trash');

        $token = $this->token();

        $this->post(sprintf('/status/thread/%d/trash', $thread->id), $token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="remove" target="thread_' . $thread->id, $this->body(), 'the row leaves the list');
        self::assertStringContainsString('Conversation moved to the bin.', $this->body());
        self::assertSame(1, preg_match(self::UNDO_URL, $this->body(), $undo), 'trashing offers an Undo');
        self::assertTrue($this->inBin($thread), 'presence companion: the trash did happen');

        $this->post($undo[0], $token);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->inBin($thread), 'the Undo takes it out of the bin');
        self::assertTrue($this->wears($thread, LabelRole::Inbox), 'and back into the Inbox');
    }

    public function testRestoreSharesTheStreamButOffersNoUndo(): void
    {
        $thread = $this->thread('Rescued');
        $this->binThread($thread, $this->trash);
        $token = $this->token();

        $this->post(sprintf('/status/thread/%d/restore', $thread->id), $token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action="remove" target="thread_' . $thread->id, $this->body(), 'presence companion: the stream is rendered');
        self::assertSame(0, preg_match(self::UNDO_URL, $this->body()), 'restore has no Undo');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function inBin(MessageThread $thread): bool
    {
        return $this->wears($thread, LabelRole::Trash);
    }

    /** Whether every message of the thread wears the role, read fresh from the database. */
    private function wears(MessageThread $thread, LabelRole $role): bool
    {
        $this->em->clear();

        $fresh = $this->em->find(MessageThread::class, $thread->id);
        self::assertNotNull($fresh);

        foreach ($fresh->messages as $message) {
            if (false === $message->labels->exists(static fn (int $key, Label $label): bool => $role === $label->role)) {
                return false;
            }
        }

        return true;
    }

    private function token(): string
    {
        return (string) $this->client->request('GET', '/mail/inbox')->filter('meta[name="csrf-token"]')->attr('content');
    }

    private function post(string $url, string $token): void
    {
        $this->client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: '{}',
        );
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
