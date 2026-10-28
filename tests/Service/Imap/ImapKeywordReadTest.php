<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Imap\ImapFlagReconciler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Keywords another client set or cleared, read back as labels (#221 step 2).
 *
 * Read as a change between two listings, never as "the server's set is the
 * truth" — a label given while keywords were off has no keyword and must stay.
 */
final class ImapKeywordReadTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Mailbox $inbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

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

    public function testAKeywordSetElsewhereBecomesTheLabel(): void
    {
        $this->account->labelsAsKeywords = true;
        $message = $this->message(['\\Seen']);

        $this->reconcile([7 => ['\\Seen', 'Follow&ACA-up', '$Junk']]);

        self::assertSame(['Follow up'], $this->labelNames($message), 'the label, made; $Junk is not a label');
    }

    public function testAKeywordClearedElsewhereTakesTheLabelOff(): void
    {
        $this->account->labelsAsKeywords = true;
        $message = $this->message(['\\Seen', 'Work']);
        $message->addLabel($this->label('Work'));
        $this->em->flush();

        $this->reconcile([7 => ['\\Seen']]);

        self::assertSame([], $this->labelNames($message));
    }

    /** A label given without a keyword is not taken off for lacking one. */
    public function testALabelWithoutAKeywordStays(): void
    {
        $this->account->labelsAsKeywords = true;
        $message = $this->message(['\\Seen']);
        $message->addLabel($this->label('Local'));
        $this->em->flush();

        $this->reconcile([7 => ['\\Flagged', '\\Seen']]);

        self::assertSame(['Local'], $this->labelNames($message));
    }

    /** Off, keywords stay in the flag mirror and nowhere else. */
    public function testWithTheSwitchOffKeywordsAreNotLabels(): void
    {
        $message = $this->message(['\\Seen']);

        $this->reconcile([7 => ['\\Seen', 'Work']]);

        self::assertSame([], $this->labelNames($message));
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * @param array<int, list<string>> $flagsByUid
     */
    private function reconcile(array $flagsByUid): void
    {
        self::getContainer()->get(ImapFlagReconciler::class)
            ->reconcile($this->inbox, $flagsByUid, new \DateTimeImmutable());
    }

    /**
     * @return list<string>
     */
    private function labelNames(Message $message): array
    {
        $this->em->refresh($message);

        $names = [];

        foreach ($message->labels as $label) {
            if (null === $label->role) {
                $names[] = (string) $label->name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param list<string> $flags
     */
    private function message(array $flags): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = 7;
        $message->subject        = 'Keyword read';
        $message->bodyText       = 'x';
        $message->hasAttachments = false;
        $message->flags          = $flags;
        $message->seenAt         = true === in_array('\\Seen', $flags, true) ? new \DateTimeImmutable() : null;
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function label(string $name): Label
    {
        $label = new Label();
        $label->usr  = $this->account->usr;
        $label->name = $name;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'keywords-read-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Key';
        $user->nameLast = 'Read';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'keywords-read@example.test';
        $account->username = 'keywords-read@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $inbox = new Mailbox();
        $inbox->account       = $account;
        $inbox->name          = 'INBOX';
        $inbox->fullPath      = 'INBOX';
        $inbox->delimiter     = '/';
        $inbox->isSyncEnabled = true;
        $this->em->persist($inbox);

        $this->em->flush();
        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
        $this->inbox   = $inbox;
    }
}
