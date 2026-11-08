<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyRemoteFlagsMessageHandler;
use App\Infrastructure\Messaging\Handler\ImportImapKeywordsHandler;
use App\Infrastructure\Messaging\Message\ApplyRemoteFlagsMessage;
use App\Infrastructure\Messaging\Message\ImportImapKeywordsMessage;
use App\Service\Imap\ImapFlagReconciler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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

    /** The line an idling connection is pushed follows the same rule. */
    public function testAKeywordPushedOverIdleBecomesTheLabel(): void
    {
        $this->account->labelsAsKeywords = true;
        $message = $this->message(['\\Seen']);

        (self::getContainer()->get(ApplyRemoteFlagsMessageHandler::class))(
            new ApplyRemoteFlagsMessage((int) $this->inbox->id, 7, ['\\Seen', 'Work']),
        );

        self::assertSame(['Work'], $this->labelNames($message));
    }

    /** Paperless-ngx writes `paperless`; the person already has Paperless (#487). */
    public function testAKeywordFindsTheLabelWhateverItsCapitals(): void
    {
        $this->account->labelsAsKeywords = true;
        $paperless = $this->label('Paperless');
        $message   = $this->message(['\\Seen']);

        $this->reconcile([7 => ['\\Seen', 'paperless']]);

        self::assertSame(['Paperless'], $this->labelNames($message));
        self::assertSame([(int) $paperless->id], $this->labelIdsNamed('paperless'), 'no twin was made');
    }

    /** Where two labels differ only in capitals, the exact spelling wins. */
    public function testTheExactSpellingWinsOverAnotherCapitalisation(): void
    {
        $this->account->labelsAsKeywords = true;
        $this->label('Work');
        $this->label('work');
        $message = $this->message(['\\Seen']);

        $this->reconcile([7 => ['\\Seen', 'work']]);

        self::assertSame(['work'], $this->labelNames($message));
    }

    /**
     * The server spelling a keyword differently from the mirror is not the
     * label taken off and put on — which, folded to one label, would end
     * with it off.
     */
    public function testAKeywordThatOnlyChangedItsCapitalsChangesNothing(): void
    {
        $this->account->labelsAsKeywords = true;
        $message = $this->message(['\\Seen', 'Paperless']);
        $message->addLabel($this->label('Paperless'));
        $this->em->flush();

        $this->reconcile([7 => ['\\Seen', 'paperless']]);

        self::assertSame(['Paperless'], $this->labelNames($message));
    }

    /** A system label is not a keyword's to give, whatever the capitals. */
    public function testAKeywordDoesNotGiveASystemLabel(): void
    {
        $this->account->labelsAsKeywords = true;
        $archive = $this->label('Archive');
        $archive->role = LabelRole::Archive;
        $this->em->flush();
        $message = $this->message(['\\Seen']);

        $this->reconcile([7 => ['\\Seen', 'archive']]);

        $this->em->refresh($message);
        self::assertFalse($message->labels->contains($archive));
    }

    /**
     * Keywords already in the mirror when the switch goes on are not a change
     * the follower would ever see; the import reads them once (#487).
     */
    public function testSwitchingOnImportsTheKeywordsAlreadyStored(): void
    {
        $this->label('Paperless');
        $message = $this->message(['\\Seen', 'paperless', 'Receipts', '$Junk']);
        $this->account->labelsAsKeywords = true;
        $this->em->flush();

        $this->importKeywords();

        self::assertSame(['Paperless', 'Receipts'], $this->labelNames($message));
        self::assertSame([], $this->queuedImports(), 'one page was enough: nothing queued after it');
    }

    /** Off again by the time the job runs: it was not wanted. */
    public function testTheImportDoesNothingWithTheSwitchOff(): void
    {
        $message = $this->message(['\\Seen', 'Receipts']);

        $this->importKeywords();

        self::assertSame([], $this->labelNames($message));
    }

    /** A full page queues the next one, from where it stopped. */
    public function testAFullPageQueuesTheNext(): void
    {
        $this->account->labelsAsKeywords = true;
        $last = $this->message(['\\Seen'], 1);

        for ($uid = 2; $uid <= ImportImapKeywordsHandler::PAGE; $uid++) {
            $last = $this->message(['\\Seen'], $uid);
        }

        $this->importKeywords();

        $queued = $this->queuedImports();
        self::assertCount(1, $queued);
        self::assertSame((int) $last->id, $queued[0]->afterId);
    }

    /** The switch going on queues the import. */
    public function testTheSwitchGoingOnQueuesTheImport(): void
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->loginUser($this->account->usr);

        $client->request('GET', '/settings/accounts/' . $this->account->id . '/folders');
        self::assertTrue($client->getResponse()->isSuccessful());
        $token = $client->getCrawler()->filter('form[action$="/folders/keywords"] input[name="_token"]')->attr('value');

        $client->request('POST', '/settings/accounts/' . $this->account->id . '/folders/keywords', ['_token' => $token]);

        $queued = $this->queuedImports();
        self::assertCount(1, $queued);
        self::assertSame((int) $this->account->id, $queued[0]->accountId);
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

    private function importKeywords(): void
    {
        (self::getContainer()->get(ImportImapKeywordsHandler::class))(
            new ImportImapKeywordsMessage((int) $this->account->id),
        );
    }

    /**
     * @return list<ImportImapKeywordsMessage>
     */
    private function queuedImports(): array
    {
        $queued = [];

        $transport = self::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof ImportImapKeywordsMessage) {
                $queued[] = $envelope->getMessage();
            }
        }

        return $queued;
    }

    /**
     * @return list<int>
     */
    private function labelIdsNamed(string $name): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM label WHERE usr_id = ? AND LOWER(name) = ? ORDER BY id',
            [$this->account->usr?->id, mb_strtolower($name)],
        ));
    }

    /**
     * @return list<string>
     */
    private function labelNames(Message $message): array
    {
        // The import clears the EntityManager; read the row again rather than
        // refreshing an object it no longer knows.
        $message = $this->em->find(Message::class, $message->id) ?? $message;
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
    private function message(array $flags, int $uid = 7): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = $uid;
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
