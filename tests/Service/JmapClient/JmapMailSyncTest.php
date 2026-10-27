<?php

declare(strict_types=1);

namespace App\Tests\Service\JmapClient;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncJmapMessageBatchMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\JmapClient\JmapAccountSyncer;
use App\Service\JmapClient\JmapEmailSyncer;
use App\Service\JmapClient\JmapImporter;
use App\Service\JmapClient\JmapImportStep;
use App\Tests\Support\Jmap\FakeJmapServer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A JMAP account synced against a server that behaves like one (#27).
 *
 * Each test is a promise the sync makes, stated as what must hold:
 *
 *  - the first sync plans an import, and the import brings in every Email
 *    once, Inbox first, through the same parser and pipeline as IMAP;
 *  - an Email's mailboxes are its labels, and its keywords its flags;
 *  - what changes on the server reaches the row on the next sync, and what is
 *    destroyed there is removed here;
 *  - a state the server can no longer answer from starts the import again
 *    rather than leaving the account stuck;
 *  - a message the account already holds by Message-ID is given the JMAP id,
 *    not stored a second time.
 */
final class JmapMailSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FakeJmapServer $server;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->server = new FakeJmapServer();
        static::getContainer()->set('app.http_client.integration', $this->server->client());

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user            = new User();
        $user->email     = 'jmap-sync-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $user->nameFirst = 'Jmap';
        $user->nameLast  = 'Sync';
        $user->password  = 'x';
        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->name             = 'JMAP fixture';
        $account->email            = 'me@example.test';
        $account->username         = 'me@example.test';
        $account->password         = 'app-password';
        $account->incomingProtocol = IncomingProtocol::Jmap;
        $account->imapHost         = 'jmap.example.test';
        $account->imapPort         = 443;
        $account->imapEncryption   = 'ssl';
        $account->authType         = 'password';
        $account->isActive         = true;
        $this->em->persist($account);
        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFirstSyncPlansAnImportThatBringsInEveryEmailOnce(): void
    {
        $this->server->deliver('In the inbox');
        $this->server->deliver('Filed away', ['mb-archive-custom']);
        $this->server->addMailbox('mb-archive-custom', 'Projects');

        $this->syncer()->sync($this->account);

        self::assertTrue($this->account->needsJmapImport(), 'a first sync plans the import');
        self::assertNotNull($this->account->jmapState(JmapEmailSyncer::STATE), 'and records where changes start');

        $this->importToTheEnd();

        self::assertSame(['Filed away', 'In the inbox'], $this->subjects());

        // Inbox first: the first page of the import is the Inbox on its own.
        $first = $this->server->callsOf('Email/query')[1];
        self::assertSame(['inMailbox' => 'mb-inbox'], $first['filter'] ?? null);

        $downloads = count($this->server->downloads);

        $this->syncer()->sync($this->account);

        self::assertSame($downloads, count($this->server->downloads), 'nothing is fetched twice');
        self::assertCount(2, $this->messages());
    }

    public function testMailboxesAreLabelsAndKeywordsAreFlags(): void
    {
        $this->server->addMailbox('mb-work', 'Work');
        $this->server->addMailbox('mb-invoices', 'Invoices', 'mb-work');
        $this->server->deliver('Invoice', ['mb-inbox', 'mb-invoices'], ['$seen', '$flagged']);

        $this->syncer()->sync($this->account);
        $this->importToTheEnd();

        $message = $this->only();
        $names   = $message->labels->map(static fn ($label) => null !== $label->role ? $label->role->value : $label->name)->toArray();
        sort($names);

        self::assertSame(['Invoices', LabelRole::Inbox->value], $names);
        self::assertSame('Work', $this->labelNamed($message, 'Invoices')->parent?->name, 'a child mailbox is a child label');
        self::assertNotNull($message->seenAt);
        self::assertNotNull($message->starredAt);
        self::assertStringStartsWith('e', (string) $message->jmapId);
        self::assertSame('t-' . $message->jmapId, $message->providerThreadKey, 'the JMAP thread id is the threading key');
        self::assertNull($message->mailbox, 'a JMAP Email lives in labels, not in a folder');
    }

    /**
     * An id is a string (RFC 8620 §1.2), but one made of digits comes back
     * from a PHP array key as an int. plMail's own server numbers its
     * mailboxes, so syncing one plMail from another failed on the first.
     */
    public function testAMailboxWhoseIdIsAllDigitsIsBound(): void
    {
        $this->server->addMailbox('7', 'Numbered');
        $this->server->deliver('Filed by number', ['mb-inbox', '7']);

        $this->syncer()->sync($this->account);
        $this->importToTheEnd();

        self::assertSame('7', $this->labelNamed($this->only(), 'Numbered')->bindingFor($this->account)?->jmapMailboxId);
    }

    public function testAChangeOnTheServerReachesTheRow(): void
    {
        $id = $this->server->deliver('Read me later');

        $this->syncer()->sync($this->account);
        $this->importToTheEnd();

        self::assertNull($this->only()->seenAt);

        $this->server->change($id, ['mb-trash'], ['$seen']);
        $this->syncer()->sync($this->account);
        $this->em->clear();

        $message = $this->only();
        $roles   = $message->labels->map(static fn ($label) => $label->role)->toArray();

        self::assertNotNull($message->seenAt, 'read on another client is read here');
        self::assertContains(LabelRole::Trash, $roles);
        self::assertNotContains(LabelRole::Inbox, $roles, 'moved out of the Inbox on the server is out of it here');
    }

    public function testNewMailArrivesThroughTheIngestQueue(): void
    {
        $this->syncer()->sync($this->account);
        $this->importToTheEnd();

        $this->server->deliver('Fresh');
        $this->syncer()->sync($this->account);

        $this->runIngestQueue();

        self::assertSame(['Fresh'], $this->subjects());
    }

    public function testAnEmailDestroyedOnTheServerIsRemovedHere(): void
    {
        $id = $this->server->deliver('Gone soon');

        $this->syncer()->sync($this->account);
        $this->importToTheEnd();
        self::assertCount(1, $this->messages());

        $this->server->destroy($id);
        $this->syncer()->sync($this->account);

        self::assertCount(0, $this->messages());
    }

    /**
     * The failure this guards: a state that has aged out answered
     * `cannotCalculateChanges` on every sync for ever, and nothing new was
     * ever read again.
     */
    public function testAStateTheServerCannotAnswerFromStartsTheImportAgain(): void
    {
        $this->syncer()->sync($this->account);
        $this->importToTheEnd();

        $this->server->deliver('While we were away');
        $this->server->forgetHistory();

        $this->syncer()->sync($this->account);

        self::assertTrue($this->account->needsJmapImport(), 'the import is planned again');

        $this->importToTheEnd();

        self::assertSame(['While we were away'], $this->subjects());
    }

    /**
     * After a switch from IMAP the account already holds this mail, keyed by
     * Message-ID. It is adopted, not stored again.
     */
    public function testAMessageAlreadyHeldByMessageIdIsAdoptedNotDuplicated(): void
    {
        $this->server->deliver('Known', ['mb-inbox'], [], '<known@example.test>');

        // The row an IMAP sync left behind: in the old INBOX folder, with
        // its server address dropped by the protocol switch.
        $folder                 = new Mailbox();
        $folder->account        = $this->account;
        $folder->name           = 'INBOX';
        $folder->fullPath       = 'INBOX';
        $folder->specialUse     = MailboxSpecialUse::INBOX;
        $folder->isSelectable   = true;
        $folder->isSyncEnabled  = true;
        $folder->importFloorUid = 0;
        $this->em->persist($folder);

        $existing                 = new Message();
        $existing->account        = $this->account;
        $existing->mailbox        = $folder;
        $existing->messageId      = 'known@example.test';
        $existing->subject        = 'Known';
        $existing->fromAddress    = 'ada@example.test';
        $existing->receivedAt     = new \DateTimeImmutable();
        $existing->syncedAt       = new \DateTimeImmutable();
        $existing->hasAttachments = false;
        $this->em->persist($existing);
        $this->em->flush();

        $this->syncer()->sync($this->account);
        $this->importToTheEnd();

        self::assertCount(1, $this->messages());
        self::assertNotNull($this->only()->jmapId);
    }

    // ---------------------------------------------------------------- helpers

    private function syncer(): JmapAccountSyncer
    {
        return static::getContainer()->get(JmapAccountSyncer::class);
    }

    private function importToTheEnd(): void
    {
        $importer = static::getContainer()->get(JmapImporter::class);

        for ($page = 0; $page < 20; ++$page) {
            $account = $this->em->find(Account::class, $this->account->id);

            if (JmapImportStep::Finished === $importer->importPage($account)) {
                break;
            }
        }

        $this->account = $this->em->find(Account::class, $this->account->id);
    }

    private function runIngestQueue(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.ingest');
        $emails    = static::getContainer()->get(JmapEmailSyncer::class);

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SyncJmapMessageBatchMessage) {
                $emails->ingest($this->em->find(Account::class, $message->accountId), $message->emailIds);
            }
        }

        $transport->reset();
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return static::getContainer()->get(MessageRepository::class)->findBy(['account' => $this->account->id]);
    }

    private function only(): Message
    {
        $messages = $this->messages();
        self::assertCount(1, $messages);

        return $messages[0];
    }

    /** @return list<string> */
    private function subjects(): array
    {
        $subjects = array_map(static fn (Message $message): string => (string) $message->subject, $this->messages());
        sort($subjects);

        return $subjects;
    }

    private function labelNamed(Message $message, string $name): \App\Entity\Label\Label
    {
        foreach ($message->labels as $label) {
            if ($name === $label->name) {
                return $label;
            }
        }

        self::fail(sprintf('no label %s', $name));
    }
}
