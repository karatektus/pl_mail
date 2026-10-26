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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * What the two reading panes' headers say about who a message is from.
 *
 * The address is the half of the sender line the sender cannot choose, so it
 * has to be as readable as the name — not the faint, truncated afterthought it
 * was — and where a Reply goes has to be printed when it is not the
 * organisation the mail claims to be from.
 *
 * Both panes are rendered, because they are two templates that each had their
 * own copy of the sender line.
 */
final class SenderHeaderRenderTest extends KernelTestCase
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function panes(): iterable
    {
        yield 'the conversation view' => ['thread'];
        yield 'the single-message view' => ['message'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('panes')]
    public function testAReplyToOnAnotherDomainIsPrintedUnderTheSender(string $pane): void
    {
        $html = $this->render($pane, $this->seedMessage('PayPal', 'service@paypal.com', 'Help <help@evil.example>'));

        self::assertStringContainsString('data-testid="reply-to-elsewhere"', $html);
        // Escaped once, as text: the angle brackets of "Help <help@…>" are
        // shown, not obeyed.
        self::assertStringContainsString('Replies go to Help &lt;help@evil.example&gt;', $html);
    }

    /** The companion: the same pane, the same sender, an answer that stays home. */
    #[\PHPUnit\Framework\Attributes\DataProvider('panes')]
    public function testAReplyToOnTheSameOrganisationPrintsNothing(string $pane): void
    {
        $html = $this->render($pane, $this->seedMessage('PayPal', 'service@paypal.com', 'help@support.paypal.com'));

        self::assertStringNotContainsString('reply-to-elsewhere', $html);
        self::assertStringNotContainsString('Replies go to', $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('panes')]
    public function testNoReplyToPrintsNothing(string $pane): void
    {
        $html = $this->render($pane, $this->seedMessage('PayPal', 'service@paypal.com', null));

        self::assertStringNotContainsString('reply-to-elsewhere', $html);
    }

    /**
     * The address is neither faint nor truncated, in the conversation view,
     * which prints it in brackets after the name.
     */
    public function testTheConversationViewPrintsTheAddressReadablyAndInFull(): void
    {
        $html = $this->render('thread', $this->seedMessage('PayPal', 'service@evil.example', null));

        $class = $this->classOfElementPrinting('&lt;service@evil.example&gt;', $html);

        self::assertNotNull($class, 'the address is printed in its own element');
        self::assertStringNotContainsString('text-ink-faint', $class);
        self::assertStringNotContainsString('truncate', $class);
        self::assertStringContainsString('break-all', $class);
    }

    public function testTheSingleMessageViewPrintsTheAddressReadablyAndInFull(): void
    {
        $html = $this->render('message', $this->seedMessage('PayPal', 'service@evil.example', null));

        $class = $this->classOfElementPrinting('service@evil.example', $html, 'p');

        self::assertNotNull($class, 'the address is printed in its own element');
        self::assertStringNotContainsString('text-ink-faint', $class);
        self::assertStringNotContainsString('truncate', $class);
        self::assertStringContainsString('break-all', $class);
    }

    /**
     * The `class` attribute of the first `<tag class="…">` whose only content
     * is $text, or null when there is none.
     */
    private function classOfElementPrinting(string $text, string $html, string $tag = 'span'): ?string
    {
        $found = preg_match(
            '/<' . $tag . '[^>]*class="([^"]*)"[^>]*>\s*' . preg_quote($text, '/') . '\s*<\/' . $tag . '>/',
            $html,
            $matches,
        );

        return 1 === $found ? $matches[1] : null;
    }

    private function render(string $pane, Message $message): string
    {
        return 'thread' === $pane
            ? $this->twig->render('mail/_thread_message.html.twig', ['message' => $message, 'expanded' => true, 'draft' => false])
            : $this->twig->render('mail/_message_content.html.twig', ['message' => $message]);
    }

    private function seedMessage(?string $name, string $address, ?string $replyTo): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Your account';
        $thread->normalizedSubject = 'your account';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-08-03');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Your account';
        $message->fromAddress    = $address;
        $message->fromName       = $name;
        $message->toAddresses    = [['name' => 'Me', 'address' => 'reader@example.test']];
        $message->bodyText       = 'Please confirm.';
        $message->receivedAt     = new DateTimeImmutable('2026-08-03 09:00:00');
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->headers        = null === $replyTo ? [] : ['reply-to' => $replyTo];

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'sender-header-' . uniqid('', true) . '@example.test';
        $user->password  = 'x';
        $user->roles     = ['ROLE_USER'];
        $user->nameFirst = 'Sender';
        $user->nameLast  = 'Fixture';

        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->name           = 'Sender header fixture';
        $this->account->email          = 'reader@example.test';
        $this->account->username       = uniqid('sh-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;

        $this->em->persist($this->account);
        $this->em->flush();
    }
}
