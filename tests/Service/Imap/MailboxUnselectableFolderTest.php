<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MailboxSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessageEraser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A folder the server marks \Noselect holds no mail and cannot be opened, so it
 * must never be polled.
 *
 * Gmail's "[Gmail]" is the usual one: a placeholder that groups All Mail, Sent
 * Mail and the rest. Polling it ends in a SELECT the server refuses, and the
 * failure was logged at ERROR level once per poll for as long as the account
 * existed. It also never records a sweep, which withholds the coverage the
 * remote-deletion rule needs (MailboxRepository::earliestSweepAcross()).
 *
 * The fixture is a Gmail-shaped listing, "/" delimited:
 *
 *   INBOX                 selectable
 *   [Gmail]               \Noselect   <- the one under test
 *   [Gmail]/All Mail      selectable
 *   [Gmail]/Drafts        selectable
 *
 * so four folders are created, and exactly three of them are sync-enabled.
 */
final class MailboxUnselectableFolderTest extends KernelTestCase
{
    private const string DELIMITER = '/';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnUnselectableFolderIsCreatedWithSyncOffAndItsSiblingsStayOn(): void
    {
        $result = $this->sync($this->gmailListing('\\Noselect'));

        // Four folders in the listing, four rows: the parent still gets its row
        // (the label tree hangs off it), it just is not polled.
        self::assertSame(4, $result['created']);

        // Presence: the three selectable folders are enabled. Without this the
        // assertion below would also pass for a syncer that enabled nothing.
        self::assertSame(
            ['INBOX', '[Gmail]/All Mail', '[Gmail]/Drafts'],
            $this->enabledPaths(),
        );

        self::assertFalse($this->mailbox('[Gmail]')->isSyncEnabled);
    }

    /**
     * RFC 3501 treats attributes as case-insensitive. The library's own
     * Folder::$no_select only matches two spellings, so the rule has to read
     * the raw attribute list itself.
     *
     * @return iterable<string, array{string}>
     */
    public static function noselectSpellings(): iterable
    {
        yield 'as Gmail sends it'   => ['\\Noselect'];
        yield 'camel case'          => ['\\NoSelect'];
        yield 'upper case'          => ['\\NOSELECT'];
        yield 'lower case'          => ['\\noselect'];
    }

    #[DataProvider('noselectSpellings')]
    public function testTheAttributeIsMatchedWithoutRegardToCase(string $attribute): void
    {
        $this->sync($this->gmailListing($attribute));

        self::assertFalse($this->mailbox('[Gmail]')->isSyncEnabled);
        // Presence companion for every spelling.
        self::assertTrue($this->mailbox('[Gmail]/All Mail')->isSyncEnabled);
    }

    /**
     * Installs that already hold the row, created enabled before the rule
     * existed, heal on the next structure sync without a migration.
     */
    public function testAnExistingEnabledRowIsSwitchedOffOnTheNextSync(): void
    {
        // First sync: the server (or an older plMail) did not mark it.
        $this->sync($this->gmailListing(null));
        self::assertTrue($this->mailbox('[Gmail]')->isSyncEnabled);

        // Second sync: now it is marked.
        $result = $this->sync($this->gmailListing('\\Noselect'));

        self::assertSame(4, $result['updated']);
        self::assertFalse($this->mailbox('[Gmail]')->isSyncEnabled);
    }

    /**
     * The fact is stored beside the switch, and unlike the switch it follows
     * the server both ways. Settings reads it to withhold the switch from a
     * placeholder, and a folder the server later makes selectable has to get
     * its switch back even though nothing turns its sync on for it.
     */
    public function testWhetherAFolderCanBeOpenedIsRecordedAndFollowsTheServerBothWays(): void
    {
        $this->sync($this->gmailListing('\\Noselect'));

        self::assertFalse($this->mailbox('[Gmail]')->isSelectable);
        // Presence: only the one the server marked.
        self::assertTrue($this->mailbox('[Gmail]/All Mail')->isSelectable);

        $this->sync($this->gmailListing(null));

        self::assertTrue($this->mailbox('[Gmail]')->isSelectable, 'selectable again, so the switch is offered');
        self::assertFalse($this->mailbox('[Gmail]')->isSyncEnabled, 'and still off until somebody turns it on');
    }

    /**
     * What the folder cost besides the log line. A folder that is polled and
     * cannot be opened never records a sweep, and one never-swept folder makes
     * MailboxRepository::earliestSweepAcross() answer null — so nothing deleted
     * elsewhere could be erased from the account, for as long as it existed.
     */
    public function testAnUnselectableFolderDoesNotWithholdSweepCoverage(): void
    {
        $swept = new DateTimeImmutable('2026-01-01 00:00:00');

        // Before the rule: all four folders enabled. The three that can be
        // opened have been swept; "[Gmail]" never can be.
        $this->sync($this->gmailListing(null));

        foreach (['INBOX', '[Gmail]/All Mail', '[Gmail]/Drafts'] as $path) {
            $this->mailbox($path)->sweptAt = $swept;
        }

        $this->em->flush();

        $repository = self::getContainer()->get(MailboxRepository::class);

        // Each answer in a variable of its own: the state changes between the
        // two calls, and PHPStan would otherwise carry the first assertion over
        // to the second call of the same expression.
        $before = $repository->earliestSweepAcross($this->account);

        self::assertNull(
            $before,
            'the contrast: an enabled folder that was never swept withholds coverage from the whole account',
        );

        // After: "[Gmail]" is switched off, so the three swept folders are all
        // that is left to cover, and the earliest of three equal times is that
        // time.
        $this->sync($this->gmailListing('\\Noselect'));

        $after = $repository->earliestSweepAcross($this->account);

        self::assertNotNull($after);
        self::assertSame('2026-01-01 00:00:00', $after->format('Y-m-d H:i:s'));
    }

    /**
     * The rule disables; it never re-enables. A selectable folder somebody
     * turned off stays off across structure syncs, or a sync toggle would be
     * undone within one poll.
     */
    public function testASelectableFolderTurnedOffByHandStaysOff(): void
    {
        $this->sync($this->gmailListing('\\Noselect'));

        $drafts = $this->mailbox('[Gmail]/Drafts');
        self::assertTrue($drafts->isSyncEnabled);

        $drafts->isSyncEnabled = false;
        $this->em->flush();

        $this->sync($this->gmailListing('\\Noselect'));

        self::assertFalse($this->mailbox('[Gmail]/Drafts')->isSyncEnabled);
        // Presence: the untouched siblings are still on.
        self::assertTrue($this->mailbox('[Gmail]/All Mail')->isSyncEnabled);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param ?string $parentAttribute the attribute on "[Gmail]", or null for none
     *
     * @return array<string, list<string>> raw path => LIST attributes
     */
    private function gmailListing(?string $parentAttribute): array
    {
        return [
            'INBOX'            => ['\\HasNoChildren'],
            '[Gmail]'          => null === $parentAttribute
                ? ['\\HasChildren']
                : [$parentAttribute, '\\HasChildren'],
            '[Gmail]/All Mail' => ['\\HasNoChildren', '\\All'],
            '[Gmail]/Drafts'   => ['\\HasNoChildren', '\\Drafts'],
        ];
    }

    /**
     * @param array<string, list<string>> $listing
     *
     * @return array{created: int, updated: int, deleted: int, renamed: int, missing: int}
     */
    private function sync(array $listing): array
    {
        $container = self::getContainer();

        $factory = self::createStub(ImapConnectionFactory::class);
        $factory->method('connect')->willReturn(new FakeListingClient($listing, self::DELIMITER));

        $syncer = new MailboxSyncer(
            $container->get(MailboxRepository::class),
            $this->em,
            $factory,
            $container->get(LabelResolver::class),
            $container->get(MessageRepository::class),
            $container->get(MessageEraser::class),
            new NullLogger(),
        );

        return $syncer->syncForAccount($this->account);
    }

    private function mailbox(string $fullPath): Mailbox
    {
        $mailbox = $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'  => $this->account,
            'fullPath' => $fullPath,
        ]);

        self::assertNotNull($mailbox, sprintf('no mailbox row for %s', $fullPath));

        return $mailbox;
    }

    /**
     * What ImapAccountSyncer would poll: the account's sync-enabled folders.
     *
     * @return list<string>
     */
    private function enabledPaths(): array
    {
        $paths = array_map(
            static fn(Mailbox $mailbox): string => (string) $mailbox->fullPath,
            $this->em->getRepository(Mailbox::class)->findBy([
                'account'       => $this->account,
                'isSyncEnabled' => true,
            ]),
        );

        sort($paths);

        return $paths;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'unselectable-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Unselectable';
        $user->nameLast = 'Folder';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Unselectable Folder Fixture';
        $account->username = 'unselectable-fixture@example.test';
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

        $this->em->flush();

        return $account;
    }
}
