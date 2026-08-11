<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * The Drafts list's who column.
 *
 * Every draft is from you, so listing the participants answered "me" on every
 * row and the column carried nothing. What distinguishes one unsent message
 * from another is who it is FOR.
 *
 * The second test is the one that earns its keep. A draft is saved as soon as
 * the body clears its minimum length, which is routinely before a recipient has
 * been typed — so `toAddresses` is null on a perfectly ordinary draft, and the
 * first version of this feature mapped over that null and took the whole Drafts
 * page down with a 500. It was the compose e2e spec that caught it, several
 * steps away from the change, because an earlier test in that file happens to
 * leave a recipient-less draft behind. This is the fast version of that catch.
 */
final class DraftRowRenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Environment $twig;
    private User $user;
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
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADraftRowNamesItsRecipientRatherThanYou(): void
    {
        $html = $this->render($this->seedDraft([
            ['name' => 'Rike Kaltbach', 'address' => 'rike@example.test'],
        ]));

        self::assertStringContainsString('Rike Kaltbach', $html);
        self::assertStringNotContainsString('>me<', $html);
    }

    /** Several recipients are all of them, not the first. */
    public function testEveryRecipientIsNamed(): void
    {
        $html = $this->render($this->seedDraft([
            ['name' => 'Rike Kaltbach', 'address' => 'rike@example.test'],
            ['name' => '', 'address' => 'jo@example.test'],
        ]));

        self::assertStringContainsString('Rike Kaltbach', $html);
        self::assertStringContainsString('jo@example.test', $html);
    }

    /**
     * A draft with nowhere to go yet renders, and says so.
     */
    public function testADraftWithNoRecipientYetStillRenders(): void
    {
        $html = $this->render($this->seedDraft(null));

        self::assertNotSame('', trim($html));
        self::assertStringContainsString('(no recipient)', $html);
    }

    /** @param list<array{name: string, address: string}>|null $to */
    private function seedDraft(?array $to): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'E2E Draft';
        $thread->normalizedSubject = 'e2e draft';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-08-03');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'E2E Draft';
        $message->fromAddress    = 'me@example.test';
        $message->fromName       = 'Me';
        $message->toAddresses    = $to;
        $message->bodyText       = 'Draft body';
        $message->receivedAt     = new DateTimeImmutable('2026-08-03');
        $message->sentAt         = null;
        $message->hasAttachments = false;
        $message->flags          = [MessageFlag::DRAFT->value];

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function render(MessageThread $thread): string
    {
        return $this->twig->render('_partials/_thread_row.html.twig', [
            'thread'      => $thread,
            'draft_scope' => true,
        ]);
    }

    private function seed(): void
    {
        $this->user           = new User();
        $this->user->email    = 'draft-row-' . uniqid('', true) . '@example.test';
        $this->user->password  = 'x';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->nameFirst = 'Draft';
        $this->user->nameLast  = 'Fixture';

        $this->em->persist($this->user);

        $this->account            = new Account();
        $this->account->usr       = $this->user;
        $this->account->name      = 'Draft row fixture';
        $this->account->email     = 'drafts@example.test';
        $this->account->username  = uniqid('drafts-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;

        $this->em->persist($this->account);
        $this->em->flush();
    }
}
