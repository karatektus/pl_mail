<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\GmailSkipReason;
use App\Entity\Mail\Account;
use App\Entity\Mail\GmailSkippedMessage;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SyncGmailMessageBatchHandler;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\GmailSkippedMessageRepository;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\ImportProgress;
use App\Service\Mail\SyncNotifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * A Gmail message that is looked at and not stored is written down.
 *
 * #90: an import that sat at 21,964 of 24,199 and said "waiting". The 2,235
 * were messages the batch handler fetches and declines — a draft, a chat, mail
 * its headers tie to no account here — and declining left no trace, so every
 * hourly listing found them missing and fetched them all again. The import
 * ended only when it ran out of attempts, a day later.
 *
 * Through the real handler and a real batch response, because the claims are
 * about what one handled batch leaves behind and what the next one does.
 */
final class GmailSkippedMessageTest extends KernelTestCase
{
    private const string GMAIL_ID = 'gmail-skipped-1';

    private EntityManagerInterface $em;
    private Connection $connection;
    private GmailSkippedMessageRepository $skipped;
    private User $user;
    private Account $account;

    /** How many requests the handler made of Gmail. */
    private int $requests = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->skipped    = $container->get(GmailSkippedMessageRepository::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** A chat: in the mailbox, and not mail. The one kind that is still declined. */
    public function testADeclinedMessageIsRecordedWithWhatTheDecisionWasMadeOn(): void
    {
        $this->handle(['CHAT'], to: 'Rike <rike@elsewhere.test>');

        self::assertSame(0, $this->storedCount(), 'declined, as before');

        $rows = $this->skipped->findForAccount($this->account);

        self::assertCount(1, $rows);
        self::assertSame(self::GMAIL_ID, $rows[0]->gmailId);
        self::assertSame(GmailSkipReason::Unattributable, $rows[0]->reason);
        self::assertSame(['CHAT'], $rows[0]->labelIds);
        self::assertSame('Rike <rike@elsewhere.test>', $rows[0]->headers['to'] ?? null);
        self::assertSame('sender@example.test', $rows[0]->headers['from'] ?? null);
        self::assertArrayNotHasKey('subject', $rows[0]->headers, 'why it was declined, not a copy of the mail');
    }

    /** The point of the record: the listing's next batch does not ask Gmail for it at all. */
    public function testADeclinedMessageIsNotFetchedAgainByTheNextListing(): void
    {
        $this->handle(['CHAT'], to: 'rike@elsewhere.test', onlyMissing: true);

        self::assertSame(1, $this->requests);

        $this->handle(['CHAT'], to: 'rike@elsewhere.test', onlyMissing: true);

        self::assertSame(1, $this->requests, 'nothing was asked for the second time');
        self::assertSame(1, $this->skipped->countForAccount($this->account));
    }

    /** A label change re-fetches it deliberately; that must not write a second row. */
    public function testDecliningTheSameMessageTwiceIsOneRecord(): void
    {
        $this->handle(['CHAT'], to: 'rike@elsewhere.test');
        $this->handle(['CHAT', 'STARRED'], to: 'rike@elsewhere.test');

        self::assertSame(2, $this->requests);
        self::assertSame(1, $this->skipped->countForAccount($this->account));
    }

    /** Gmail lists it and answers 404 for it: not coming, and not to be asked for hourly either. */
    public function testAMessageGmailCannotProduceIsRecordedAsUnfetchable(): void
    {
        $this->handle([], to: '', status: 404);

        $rows = $this->skipped->findForAccount($this->account);

        self::assertCount(1, $rows);
        self::assertSame(GmailSkipReason::Unfetchable, $rows[0]->reason);
        self::assertSame([], $rows[0]->headers);
    }

    /**
     * Declined once is not declined for good. A message recorded under the
     * old rule — which dropped anything whose headers did not name this
     * account — is stored when it is next looked at, and its record goes.
     * This is the upgrade path for every mailbox the old rule ran over.
     */
    public function testAMessageThatBecomesAttributableIsStoredAndComesOffTheRecord(): void
    {
        $this->em->persist(new GmailSkippedMessage($this->account, self::GMAIL_ID, GmailSkipReason::Unattributable, ['INBOX']));
        $this->em->flush();

        $this->handle(['INBOX'], to: 'somebody-else@elsewhere.test');

        self::assertSame(1, $this->storedCount());
        self::assertSame(0, $this->skipped->countForAccount($this->account));
    }

    // ── who a message belongs to (#90) ───────────────────────────────────────

    /**
     * The four kinds `app:gmail:skipped` found on a real mailbox, which the
     * old rule dropped and none of which belongs to any other account.
     *
     * @return iterable<string, array{0: list<string>, 1: string, 2: string, 3: ?string}>
     */
    public static function mailInThisMailbox(): iterable
    {
        yield 'sent from a "send mail as" address' => [['SENT'], 'me@work.test', 'client@elsewhere.test', null];
        yield 'delivered to a forwarding address not connected here' => [['INBOX'], 'sender@example.test', 'me@old-isp.test', 'me@old-isp.test'];
        yield 'addressed to the googlemail spelling of the account' => [['INBOX'], 'sender@example.test', 'gmail@googlemail.com', null];
        yield 'with no recipient headers at all' => [['INBOX'], 'sender@example.test', '', null];
    }

    /**
     * @param list<string> $labelIds
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailInThisMailbox')]
    public function testMailInThisMailboxThatNoOtherAccountClaimsIsThisAccounts(array $labelIds, string $from, string $to, ?string $deliveredTo): void
    {
        $this->handle($labelIds, to: $to, from: $from, deliveredTo: $deliveredTo);

        self::assertSame(1, $this->storedCount());
        self::assertSame(0, $this->skipped->countForAccount($this->account));
    }

    public function testMailToTheGooglemailSpellingOfAGmailAddressIsThatAddresses(): void
    {
        $filter = self::getContainer()->get(\App\Service\Gmail\GmailAddressFilter::class);

        self::assertSame($filter->normalise('Kara.Tektus@gmail.com'), $filter->normalise('karatektus@googlemail.com'));
        self::assertNotSame($filter->normalise('karatektus@gmail.com'), $filter->normalise('karatektus@googlemail.de'));
    }

    /**
     * The duplicate the new rule could otherwise make. Gmail collected this
     * from another connected account — a Bcc'd newsletter, with nothing of
     * that address left in its headers — and that account's own sync holds
     * it already. It names nobody, so it would have been stored here as
     * well; it is merged into the copy that exists instead.
     */
    public function testAnUnclaimedMessageAnotherAccountAlreadyHoldsIsMergedNotStoredTwice(): void
    {
        $sibling                = new Account();
        $sibling->usr           = $this->user;
        $sibling->name          = 'Work';
        $sibling->email         = 'me@work.test';
        $sibling->username      = uniqid('work-', true);
        $sibling->authType      = AuthType::Password->value;
        $sibling->imapHost      = 'imap.work.test';
        $sibling->isActive      = true;
        $this->em->persist($sibling);

        // On a conversation of its own account, as every stored message is:
        // that is how a copy is found (findOneForAccountByMessageId).
        $thread                    = new \App\Entity\Mail\MessageThread();
        $thread->account           = $sibling;
        $thread->subject           = 'Newsletter';
        $thread->normalizedSubject = 'newsletter';
        $thread->threadingMethod   = \App\Domain\Enum\Mail\ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $this->em->persist($thread);

        $copy              = new Message();
        $copy->account     = $sibling;
        $copy->thread      = $thread;
        $copy->subject     = 'Newsletter';
        $copy->fromAddress = 'news@list.test';
        $copy->messageId   = 'gmail-skipped-1@example.test';
        $copy->receivedAt  = new DateTimeImmutable('-1 hour');
        $copy->hasAttachments = false;
        $copy->flags       = [];
        $this->em->persist($copy);
        $this->em->flush();

        $this->handle(['INBOX'], to: 'list-members@list.test', from: 'news@list.test');

        self::assertSame(0, $this->storedCount(), 'not a second copy on the Gmail account');

        $this->em->refresh($copy);

        self::assertSame(self::GMAIL_ID, $copy->gmailId, 'the copy that exists learns its Gmail id instead');

        // And the import line counts it: it is in, just not on this account.
        $this->account->importTotal = 1;
        $this->em->flush();

        self::assertSame(1, self::getContainer()->get(ImportProgress::class)->forUser($this->user)[0]->done);
    }

    // ── another Gmail account's own copy (discussion #168) ───────────────────

    /**
     * Mail forwarded from one connected Gmail account to another: Delivered-To
     * names the first, so it is attributed there, and that account's own sync
     * holds it under its own Gmail id. Merging used to write this mailbox's id
     * over that one — the row then answered to neither mailbox properly, and
     * its own sync stored the message a second time.
     */
    public function testAnotherGmailAccountsOwnCopyKeepsItsGmailId(): void
    {
        $sibling = $this->seedGmailSibling();
        $copy    = $this->seedCopy($sibling, 'sibling-own-id');

        $this->handle(['INBOX'], to: 'other@gmail.com', deliveredTo: 'other@gmail.com');

        $this->em->refresh($copy);

        self::assertSame('sibling-own-id', $copy->gmailId, 'its own mailbox\'s id, not this one\'s');
        self::assertNull($copy->gmailCarrierAccount);
        self::assertSame(0, $this->storedCount(), 'not a second copy on this account either');

        $rows = $this->skipped->findForAccount($this->account);

        self::assertCount(1, $rows, 'written down, so the import counts it and stops asking');
        self::assertSame(\App\Domain\Enum\Mail\GmailSkipReason::HeldElsewhere, $rows[0]->reason);

        // A label change re-fetches it; the record stays, and stays one.
        $this->handle(['INBOX', 'STARRED'], to: 'other@gmail.com', deliveredTo: 'other@gmail.com');

        self::assertSame(1, $this->skipped->countForAccount($this->account));
    }

    /** The same, for a message nobody claims and the other Gmail account holds. */
    public function testAnUnclaimedMessageAnotherGmailAccountHoldsIsNotMergedIntoIt(): void
    {
        $sibling = $this->seedGmailSibling();
        $copy    = $this->seedCopy($sibling, 'sibling-own-id');

        $this->handle(['INBOX'], to: 'list-members@list.test', from: 'news@list.test');

        $this->em->refresh($copy);

        self::assertSame('sibling-own-id', $copy->gmailId);
        self::assertSame(0, $this->storedCount());
    }

    /**
     * What an installation the old merge ran over looks like: the other
     * account's row carries this mailbox's id, and its own sync has stored
     * the message again under its own. Two rows, one Message-ID. Handling
     * this id again picked either by Message-ID and wrote the id onto it —
     * onto the second row, a duplicate key, and the whole batch failed and
     * was fetched again on every retry.
     */
    public function testAnIdAlreadyMergedIsFoundByItselfNotByMessageId(): void
    {
        $sibling = $this->seedGmailSibling();
        $own     = $this->seedCopy($sibling, 'sibling-own-id');
        $merged  = $this->seedCopy($sibling, self::GMAIL_ID);

        $merged->gmailCarrierAccount = $this->account;
        $this->em->flush();

        $this->handle(['INBOX'], to: 'other@gmail.com', deliveredTo: 'other@gmail.com');
        $this->handle(['INBOX', 'STARRED'], to: 'other@gmail.com', deliveredTo: 'other@gmail.com');

        $this->em->refresh($own);
        $this->em->refresh($merged);

        self::assertSame('sibling-own-id', $own->gmailId);
        self::assertSame(self::GMAIL_ID, $merged->gmailId);
        self::assertSame(['INBOX', 'STARRED'], $merged->gmailLabelIds);
    }

    /** A Gmail draft is a draft here: in Drafts, flagged as one. */
    public function testAGmailDraftIsStoredAsADraft(): void
    {
        $this->handle(['DRAFT'], to: 'rike@elsewhere.test');

        $draft = $this->em->getRepository(Message::class)->findOneBy(['account' => $this->account]);

        self::assertNotNull($draft);
        self::assertTrue($draft->isDraft());
    }

    /**
     * 21,964 of 24,199 with nothing still to come. The total is Google's count
     * of the mailbox, so what was looked at and declined has to count as done.
     */
    public function testTheImportLineCountsADeclinedMessageAsDealtWith(): void
    {
        $this->account->importTotal = 1;
        $this->em->flush();

        $progress = self::getContainer()->get(ImportProgress::class);

        self::assertSame(0, $progress->forUser($this->user)[0]->done);

        $this->handle(['CHAT'], to: 'rike@elsewhere.test');

        self::assertSame(1, $progress->forUser($this->user)[0]->done);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function storedCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.account = :account',
        )->setParameter('account', $this->account)->getSingleScalarResult();
    }

    /**
     * Run the batch handler for the one message, with Gmail answering this.
     *
     * @param list<string> $labelIds
     */
    private function handle(
        array $labelIds,
        string $to,
        int $status = 200,
        bool $onlyMissing = false,
        string $from = 'sender@example.test',
        ?string $deliveredTo = null,
    ): void {
        $payload = json_encode([
            'id'           => self::GMAIL_ID,
            'threadId'     => 'gmail-skipped-thread',
            'labelIds'     => $labelIds,
            'internalDate' => (string) (time() * 1000),
            'payload'      => [
                'headers' => array_values(array_filter([
                    ['name' => 'Message-ID', 'value' => '<gmail-skipped-1@example.test>'],
                    ['name' => 'Subject', 'value' => 'About the invoice'],
                    ['name' => 'From', 'value' => $from],
                    '' === $to ? null : ['name' => 'To', 'value' => $to],
                    null === $deliveredTo ? null : ['name' => 'Delivered-To', 'value' => $deliveredTo],
                ])),
            ],
        ], JSON_THROW_ON_ERROR);

        $boundary = 'batch_plmail_test';

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/http\r\n"
            . "Content-ID: <response-" . self::GMAIL_ID . ">\r\n"
            . "\r\n"
            . (200 === $status ? "HTTP/1.1 200 OK\r\n" : "HTTP/1.1 {$status} Not Found\r\n")
            . "Content-Type: application/json\r\n"
            . "\r\n"
            . (200 === $status ? $payload : '{"error":{"code":' . $status . ',"message":"Requested entity was not found."}}') . "\r\n"
            . "--{$boundary}--";

        $httpClient = new MockHttpClient(function () use ($body, $boundary): MockResponse {
            ++$this->requests;

            return new MockResponse($body, [
                'response_headers' => ['content-type' => 'multipart/mixed; boundary="' . $boundary . '"'],
            ]);
        });

        $container = self::getContainer();
        $pacer     = new \App\Service\Gmail\GmailQuotaPacer(new \Symfony\Component\Clock\MockClock());

        $handler = new SyncGmailMessageBatchHandler(
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Repository\Mail\AccountRepository'),
            new GmailApiClient($httpClient, $container->get('App\Service\OAuth\OAuthTokenManager'), $pacer),
            $container->get('App\Service\Gmail\GmailMessageBuilder'),
            $container->get('App\Service\Gmail\GmailAddressFilter'),
            $container->get('App\Service\HarvestContactsService'),
            // The real notifier publishes to Mercure, which the test kernel has
            // no signing key for. What it announces is not what is under test.
            new SyncNotifier($this->createStub(HubInterface::class), new NullLogger()),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Mail\PostIngestPipeline'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            new NullLogger(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            $container->get('App\Service\Mail\MessageCategorizer'),
            $container->get('App\Service\Imap\MessageThreader'),
            new \App\Service\Mail\SyncOrigin(),
            $pacer,
            $this->skipped,
        );

        $handler(new SyncGmailMessageBatchMessage((int) $this->account->id, [self::GMAIL_ID], null, $onlyMissing));

        $this->em->flush();
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Gmail fixture';
        $account->email          = 'gmail@example.test';
        $account->username       = uniqid('gmail-', true);
        $account->imapHost       = 'imap.gmail.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';

        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'google';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        $this->user->addAccount($account);
        $this->em->flush();

        return $account;
    }

    /** A second Gmail account of the same user, synced through the API itself. */
    private function seedGmailSibling(): Account
    {
        $sibling                   = new Account();
        $sibling->usr              = $this->user;
        $sibling->name             = 'Other Gmail';
        $sibling->email            = 'other@gmail.com';
        $sibling->username         = uniqid('gmail-other-', true);
        $sibling->imapHost         = 'imap.gmail.com';
        $sibling->authType         = AuthType::OAuth2->value;
        $sibling->oauthProvider    = 'google';
        $sibling->oauthAccessToken = 'test-access-token';
        $sibling->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $sibling->isActive         = true;
        $this->em->persist($sibling);
        $this->em->flush();

        return $sibling;
    }

    /** That account's copy of the test message, on a conversation of its own. */
    private function seedCopy(Account $owner, string $gmailId): Message
    {
        $thread                    = new \App\Entity\Mail\MessageThread();
        $thread->account           = $owner;
        $thread->subject           = 'About the invoice';
        $thread->normalizedSubject = 'about the invoice';
        $thread->threadingMethod   = \App\Domain\Enum\Mail\ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $this->em->persist($thread);

        $copy                 = new Message();
        $copy->account        = $owner;
        $copy->thread         = $thread;
        $copy->subject        = 'About the invoice';
        $copy->fromAddress    = 'sender@example.test';
        $copy->messageId      = 'gmail-skipped-1@example.test';
        $copy->gmailId        = $gmailId;
        $copy->gmailLabelIds  = ['INBOX'];
        $copy->receivedAt     = new DateTimeImmutable('-1 hour');
        $copy->hasAttachments = false;
        $copy->flags          = [];
        $this->em->persist($copy);
        $this->em->flush();

        return $copy;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'gmail-skipped-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'Skipped';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
