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
 * The list row prints where a message really comes from, next to who it says
 * it is from.
 *
 * A row used to print display names only, and a display name is whatever the
 * sender typed: `"PayPal" <service@evil.example>` looked exactly like the real
 * one. The domain is the part they cannot choose.
 *
 * Every expectation is a domain written out by hand from the fixture address.
 * Each "shows it" test has a companion that shows it is not simply printed
 * everywhere — an assertion that something is absent proves nothing on its own
 * when the fixture might be empty.
 */
final class SenderDomainRowRenderTest extends KernelTestCase
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
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAConversationRowShowsTheDomainBesideTheName(): void
    {
        $thread = $this->seedThread(['PayPal', 'service@evil.example']);

        $domains = $this->domainsIn($this->renderThread($thread));

        self::assertSame(['evil.example'], $domains);
        self::assertStringContainsString('PayPal', $this->renderThread($thread));
    }

    /** The companion: it is the ADDRESS that decides, not the name. */
    public function testTheDomainIsTheOneOfTheAddressNotOfTheName(): void
    {
        self::assertSame(
            ['paypal.com'],
            $this->domainsIn($this->renderThread($this->seedThread(['PayPal', 'service@paypal.com']))),
        );
    }

    public function testASenderWithNoNameShowsTheAddressAndNoSeparateDomain(): void
    {
        $html = $this->renderThread($this->seedThread([null, 'service@evil.example']));

        self::assertSame([], $this->domainsIn($html));
        self::assertStringContainsString('service@evil.example', $html);
    }

    public function testTheReaderIsNotGivenADomain(): void
    {
        // drafts@example.test is the fixture account's own address.
        $html = $this->renderThread($this->seedThread(['Me Myself', 'drafts@example.test']));

        self::assertSame([], $this->domainsIn($html));
        self::assertStringContainsString('>me<', preg_replace('/\s+/', '', $html) ?? '');
    }

    /** The spoof inside a conversation: same name, two domains, two entries. */
    public function testTheSameNameFromTwoDomainsIsShownTwice(): void
    {
        $thread = $this->seedThread(
            ['PayPal', 'service@paypal.com'],
            ['PayPal', 'service@evil.example'],
        );

        self::assertSame(
            ['paypal.com', 'evil.example'],
            $this->domainsIn($this->renderThread($thread)),
        );
    }

    /** A row that is one message, not a conversation, reads the same. */
    public function testASingleMessageRowShowsTheDomainToo(): void
    {
        $message = $this->seedThread(['PayPal', 'service@evil.example'])->messages->first();

        $html = $this->twig->render('_partials/_thread_row.html.twig', ['message' => $message]);

        self::assertSame(['evil.example'], $this->domainsIn($html));
    }

    public function testASingleMessageRowWithNoNameShowsNoSeparateDomain(): void
    {
        $message = $this->seedThread([null, 'service@evil.example'])->messages->first();

        $html = $this->twig->render('_partials/_thread_row.html.twig', ['message' => $message]);

        self::assertSame([], $this->domainsIn($html));
        self::assertStringContainsString('service@evil.example', $html);
    }

    /**
     * Sent and Drafts name who the mail is FOR. Nothing there is a claim of
     * identity, so nothing there gets a domain beside it.
     */
    public function testTheSentListNamesRecipientsWithoutDomains(): void
    {
        $thread = $this->seedThread(['Me Myself', 'drafts@example.test']);
        $thread->messages->first()->toAddresses = [['name' => 'Rike Kaltbach', 'address' => 'rike@example.test']];
        $this->em->flush();

        $html = $this->twig->render('_partials/_thread_row.html.twig', [
            'thread'     => $thread,
            'sent_scope' => true,
        ]);

        // "To: ..." is the Sent row's own phrase, so this also fails if the row
        // stops using its recipients and falls back to the sender column.
        self::assertStringContainsString('To: Rike Kaltbach', $html);
        self::assertSame([], $this->domainsIn($html));
    }

    /** Drafts are the same: the row names who the draft is FOR. */
    public function testTheDraftsListNamesRecipientsWithoutDomains(): void
    {
        // Not the account's own address, on purpose: a draft is written by the
        // reader, but nothing stops its from-address being one the account does
        // not list, and that is the case that would otherwise grow a domain.
        $thread  = $this->seedThread(['Me', 'me.elsewhere@example.test']);
        $message = $thread->messages->first();

        $message->toAddresses = [['name' => 'Rike Kaltbach', 'address' => 'rike@example.test']];
        $message->flags       = [MessageFlag::DRAFT->value];
        $this->em->flush();
        $this->em->refresh($thread);

        $html = $this->twig->render('_partials/_thread_row.html.twig', [
            'thread'      => $thread,
            'draft_scope' => true,
        ]);

        self::assertStringContainsString('To: Rike Kaltbach', $html);
        self::assertSame([], $this->domainsIn($html));
    }

    /**
     * @return list<string> the text of every domain the row printed, in order
     */
    private function domainsIn(string $html): array
    {
        preg_match_all('/<span[^>]*data-sender-domain[^>]*>\s*([^<]*?)\s*<\/span>/', $html, $matches);

        return $matches[1];
    }

    private function renderThread(MessageThread $thread): string
    {
        return $this->twig->render('_partials/_thread_row.html.twig', ['thread' => $thread]);
    }

    /**
     * @param array{0: ?string, 1: string} ...$senders [display name, address], oldest first
     */
    private function seedThread(array ...$senders): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Your account';
        $thread->normalizedSubject = 'your account';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-08-03');

        $this->em->persist($thread);

        foreach ($senders as $at => [$name, $address]) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->thread         = $thread;
            $message->subject        = 'Your account';
            $message->fromAddress    = $address;
            $message->fromName       = $name;
            $message->toAddresses    = [['name' => 'Me', 'address' => 'drafts@example.test']];
            $message->bodyText       = 'Please confirm.';
            $message->receivedAt     = new DateTimeImmutable('2026-08-03 09:0' . $at . ':00');
            $message->hasAttachments = false;
            $message->flags          = [];

            $thread->addMessage($message);
            $this->em->persist($message);
        }

        $this->em->flush();
        $this->em->refresh($thread);

        return $thread;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'sender-domain-' . uniqid('', true) . '@example.test';
        $user->password  = 'x';
        $user->roles     = ['ROLE_USER'];
        $user->nameFirst = 'Sender';
        $user->nameLast  = 'Fixture';

        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->name           = 'Sender domain fixture';
        $this->account->email          = 'drafts@example.test';
        $this->account->username       = uniqid('sd-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;

        $this->em->persist($this->account);
        $this->em->flush();
    }
}
