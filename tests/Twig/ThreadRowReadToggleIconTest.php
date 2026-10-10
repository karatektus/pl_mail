<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * The envelope on a row's read toggle names what pressing it does.
 *
 * The report: the mark-as-read button used the closed envelope, and only in the
 * Promotions tab. Nothing is special about Promotions — it is simply where mail
 * is unread. The row drew the envelope the conversation IS (closed while
 * unread, open once read), so an unread row's "Mark as read" wore the closed
 * envelope and a read row's "Mark as unread" wore the open one. The selection
 * toolbar draws the opposite, the action: open envelope for "Mark as read",
 * closed for "Mark as unread". Anywhere that is mostly read, which is most
 * lists, the row looked right by accident.
 *
 * Both rows are asserted so that "always the open envelope" and "swapped back"
 * each fail: the pair only means something together.
 */
final class ThreadRowReadToggleIconTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Environment $twig;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->twig       = $container->get(Environment::class);

        // The row renders CSRF-bearing controls, and the token manager reads the
        // session off the request stack — empty outside a real request.
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

    public function testAnUnreadRowOffersMarkAsReadWithTheOpenEnvelope(): void
    {
        $button = $this->readToggle($this->seedThread(unreadCount: 1));

        self::assertSame('true', $button->attr('data-mail--message-row-read-param'), 'pressing it marks the row read');
        self::assertSame('Mark as read', $button->attr('title'));
        self::assertSame(['fa-solid', 'fa-envelope-open'], $this->iconClasses($button));
    }

    public function testAReadRowOffersMarkAsUnreadWithTheClosedEnvelope(): void
    {
        $button = $this->readToggle($this->seedThread(unreadCount: 0));

        self::assertSame('false', $button->attr('data-mail--message-row-read-param'), 'pressing it marks the row unread');
        self::assertSame('Mark as unread', $button->attr('title'));
        self::assertSame(['fa-solid', 'fa-envelope'], $this->iconClasses($button));
    }

    private function readToggle(MessageThread $thread): Crawler
    {
        $html = $this->twig->render('_partials/_thread_row.html.twig', ['thread' => $thread]);

        $button = new Crawler($html)->filter('button[data-action*="message-row#markRead"]');

        self::assertCount(1, $button, 'one read toggle on the row');

        return $button;
    }

    /** @return list<string> the icon's classes, minus the sizing utilities */
    private function iconClasses(Crawler $button): array
    {
        $classes = preg_split('/\s+/', trim((string) $button->filter('i')->attr('class'))) ?: [];

        return array_values(array_filter($classes, static fn (string $c): bool => str_starts_with($c, 'fa-')));
    }

    private function seedThread(int $unreadCount): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Read toggle';
        $thread->normalizedSubject = 'read toggle';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-08-03');
        $thread->unreadCount       = $unreadCount;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Read toggle';
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->toAddresses    = [['name' => 'Me', 'address' => 'me@example.test']];
        $message->bodyText       = 'Body';
        $message->receivedAt     = new DateTimeImmutable('2026-08-03');
        $message->hasAttachments = false;

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function seedAccount(): void
    {
        $user            = new User();
        $user->email     = 'read-toggle-' . uniqid('', true) . '@example.test';
        $user->password  = 'x';
        $user->roles     = ['ROLE_USER'];
        $user->nameFirst = 'Read';
        $user->nameLast  = 'Toggle';

        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->name           = 'Read toggle fixture';
        $this->account->email          = 'toggle@example.test';
        $this->account->username       = uniqid('toggle-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;

        $this->em->persist($this->account);
        $this->em->flush();
    }
}
