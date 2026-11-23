<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SafeHtmlUnknownElementBackfillTask;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\MailBodySanitizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * How mail stored blank because its letter sat inside an unknown element
 * (Nayax's `<message>`) gets its body back, from the sender's own copy.
 */
final class SafeHtmlUnknownElementBackfillTest extends KernelTestCase
{
    private const string CARD = '<html><body><message id="mail_body"><table><tr><td>Your refund request was approved.</td></tr></table></message></body></html>';

    private EntityManagerInterface $em;
    private Connection $connection;
    private SafeHtmlUnknownElementBackfillTask $task;
    private MailBodySanitizer $sanitizer;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(SafeHtmlUnknownElementBackfillTask::class);
        $this->sanitizer  = $container->get(MailBodySanitizer::class);

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

    public function testItGivesABlankBodyItsLetterBack(): void
    {
        // What the sanitizer used to write: nothing.
        $message = $this->message(self::CARD, "\n");
        $id      = (int) $message->id;

        self::assertSame(Command::SUCCESS, $this->runTask());

        self::assertStringContainsString('Your refund request was approved.', (string) $this->reload($id)->bodyHtmlSafe);
    }

    /** The sender's own copy is what the repair reads, and stays as it was. */
    public function testItLeavesTheRawBodyAlone(): void
    {
        $message = $this->message(self::CARD, '');
        $id      = (int) $message->id;

        $this->runTask();

        self::assertSame(self::CARD, $this->reload($id)->bodyHtml);
    }

    /**
     * The walk is over every HTML body, which is far more mail than was ever
     * damaged — so it has to be a no-op on the rest.
     */
    public function testItLeavesAnUnaffectedBodyUnchanged(): void
    {
        $message = $this->message(
            '<html><body><a href="https://example.test/">Hier</a><table><tr><td>daneben</td></tr></table></body></html>',
            '',
        );

        $this->sanitizer->sanitize($message);
        $this->em->flush();

        $id     = (int) $message->id;
        $before = (string) $message->bodyHtmlSafe;

        $this->runTask();

        self::assertSame($before, (string) $this->reload($id)->bodyHtmlSafe);
    }

    /**
     * A body made only of elements the allow-list names is not sanitised
     * again at all: the walk reads every HTML body, but only the ones with an
     * unknown element are worth the CSS inliner and two parses. The stored
     * copy here is deliberately not what the sanitizer would write, so a
     * second sanitise would show.
     */
    public function testItSkipsABodyWithoutAnUnknownElement(): void
    {
        $message = $this->message(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Hi</title><style>p{margin:0}</style></head>'
            . '<body><p>Nothing unknown here.<br><img src="https://example.test/a.png" alt=""></p></body></html>',
            '<p>stored earlier</p>',
        );
        $id      = (int) $message->id;

        $this->runTask();

        self::assertSame('<p>stored earlier</p>', $this->reload($id)->bodyHtmlSafe);
    }

    private function runTask(): int
    {
        return $this->task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }

    private function reload(int $id): Message
    {
        $message = $this->em->getRepository(Message::class)->find($id);

        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    private function message(string $bodyHtml, string $bodyHtmlSafe): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Refund Confirmation';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<%s@example.test>', uniqid('', true));
        $message->bodyHtml       = $bodyHtml;
        $message->bodyHtmlSafe   = $bodyHtmlSafe;

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'unknownel-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Unknownel';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Unknownel Fixture';
        $account->username       = 'unknownel-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;
    }
}
