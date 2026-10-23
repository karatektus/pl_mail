<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * A message in the bin, inside a conversation that is not.
 *
 * A conversation stays in the Inbox and its labels until every message of it
 * is deleted (#268), so opening it shows deleted messages among live ones. The
 * Trash label is a system label and the label chips never draw those, so
 * nothing said which message was which.
 *
 * The mark is for a MIXED conversation only. In Trash every message is
 * deleted, and a chip on each of them would say nothing.
 */
final class BinnedMessageMarkTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Environment $twig;
    private LabelResolver $labels;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->twig       = $container->get(Environment::class);
        $this->labels     = $container->get(LabelResolver::class);

        // The message actions render CSRF-bearing controls, and the token
        // manager reads the session off the request stack.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $this->connection->beginTransaction();
        $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADeletedMessageAmongLiveOnesIsMarked(): void
    {
        $thread = $this->seedThread([false, true, false]);

        $html = $this->renderThread($thread);

        self::assertSame(1, substr_count($html, 'data-testid="message-in-trash"'), 'one chip, on the deleted message');
        self::assertSame(1, substr_count($html, 'data-in-trash="true"'));
        self::assertStringContainsString('In Trash', $html);

        // And on the right one: the attribute sits on the message root the
        // chip is inside, not on a neighbour.
        $binned = $thread->messages->toArray()[1];
        self::assertMatchesRegularExpression(
            '/id="thread_message_' . $binned->id . '"[^>]*data-in-trash="true"/s',
            $html,
        );
    }

    public function testNothingIsMarkedWhileEveryMessageIsInTheBin(): void
    {
        $html = $this->renderThread($this->seedThread([true, true]));

        self::assertStringNotContainsString('message-in-trash', $html);
        self::assertStringNotContainsString('data-in-trash', $html);
    }

    public function testNothingIsMarkedWhenNoMessageIsInTheBin(): void
    {
        $html = $this->renderThread($this->seedThread([false, false]));

        self::assertStringNotContainsString('message-in-trash', $html);
        self::assertStringNotContainsString('data-in-trash', $html);
    }

    /** @param list<bool> $inBin one entry per message, oldest first */
    private function seedThread(array $inBin): MessageThread
    {
        $trash = $this->labels->systemLabel(LabelRole::Trash, $this->account);
        $inbox = $this->labels->systemLabel(LabelRole::Inbox, $this->account);

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Binned mark';
        $thread->normalizedSubject = 'binned mark';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-08-03');

        $this->em->persist($thread);

        foreach ($inBin as $i => $deleted) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->thread         = $thread;
            $message->subject        = 'Binned mark';
            $message->fromAddress    = 'sender' . $i . '@example.test';
            $message->fromName       = 'Sender ' . $i;
            $message->toAddresses    = [['name' => 'Me', 'address' => 'me@example.test']];
            $message->bodyText       = 'Body ' . $i;
            $message->receivedAt     = new DateTimeImmutable('2026-08-03T10:0' . $i . ':00');
            $message->hasAttachments = false;
            $message->flags          = [];
            $message->addLabel($deleted ? $trash : $inbox);

            $thread->addMessage($message);
            $this->em->persist($message);
        }

        $this->em->flush();

        // The thread carries the union of its messages' labels, as sync leaves
        // it; the view is meant to decide per message, not by that union.
        foreach ($thread->messages as $message) {
            foreach ($message->labels as $label) {
                $thread->addLabel($label);
            }
        }

        $this->em->flush();

        return $thread;
    }

    private function renderThread(MessageThread $thread): string
    {
        return $this->twig->render('mail/_thread_content.html.twig', [
            'thread'   => $thread,
            'messages' => $thread->messages->toArray(),
        ]);
    }

    private function seedAccount(): void
    {
        $user            = new User();
        $user->email     = 'binned-mark-' . uniqid('', true) . '@example.test';
        $user->password  = 'x';
        $user->roles     = ['ROLE_USER'];
        $user->nameFirst = 'Binned';
        $user->nameLast  = 'Fixture';

        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->name           = 'Binned mark fixture';
        $this->account->email          = 'binned@example.test';
        $this->account->username       = uniqid('binned-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;

        $this->em->persist($this->account);
        $this->em->flush();
    }
}
