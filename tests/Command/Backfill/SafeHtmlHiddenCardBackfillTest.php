<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SafeHtmlHiddenCardBackfillTask;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\MailBodySanitizer;
use Dom\HTMLDocument;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * How a review request whose product card was dropped or hidden gets it back.
 *
 * bodyHtml still has the card, inside its form and switched on by its
 * stylesheet; bodyHtmlSafe has nothing where the form was (#271). Sanitising
 * again repairs it, with no mail server involved.
 */
final class SafeHtmlHiddenCardBackfillTest extends KernelTestCase
{
    private const string CARD = '<html><head><style>.d {display:block !important}</style></head><body>'
        . '<form action="https://reviews.example.test/r"><div class="d" style="display: none;">'
        . '<img src="https://media.example.test/card.jpg" alt="">Universal-Drahtbürste</div></form>'
        . '</body></html>';

    private EntityManagerInterface $em;
    private Connection $connection;
    private SafeHtmlHiddenCardBackfillTask $task;
    private MailBodySanitizer $sanitizer;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(SafeHtmlHiddenCardBackfillTask::class);
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

    public function testItBringsTheCardBackAndShowsIt(): void
    {
        // What the sanitizer used to write: the form gone, and the card with it.
        $message = $this->message(self::CARD, '');
        $id      = (int) $message->id;

        self::assertSame(Command::SUCCESS, $this->runTask());

        $image = HTMLDocument::createFromString((string) $this->reload($id)->bodyHtmlSafe, LIBXML_NOERROR, 'UTF-8')->querySelector('img');

        self::assertSame('https://media.example.test/card.jpg', $image?->getAttribute('src'));
        self::assertStringContainsString('display: block', (string) $image->parentElement?->getAttribute('style'));
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
     * The walk is over every body with a form or a stylesheet `!important`,
     * which is far more mail than was ever hidden — so it has to be a no-op on
     * the rest.
     */
    public function testItLeavesAnUnaffectedBodyUnchanged(): void
    {
        $message = $this->message(
            '<html><head><style>p {color: red !important}</style></head><body><p>Hallo</p></body></html>',
            '',
        );

        $this->sanitizer->sanitize($message);
        $this->em->flush();

        $id     = (int) $message->id;
        $before = (string) $message->bodyHtmlSafe;

        $this->runTask();

        self::assertSame($before, (string) $this->reload($id)->bodyHtmlSafe);
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
        $message->subject        = 'Ihre Meinung ist uns wichtig!';
        $message->fromAddress    = 'no-reply@example.test';
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
        $user->email     = 'hiddencard-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Hiddencard';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Hiddencard Fixture';
        $account->username       = 'hiddencard-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;
    }
}
