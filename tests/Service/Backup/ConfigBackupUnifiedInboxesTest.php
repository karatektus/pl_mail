<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Mail\Account;
use App\Entity\Mail\UnifiedInbox;
use App\Entity\User\User;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Backup\ConfigBackupExporter;
use App\Service\Backup\ConfigBackupImporter;
use App\Service\Mail\UnifiedInboxes;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A reader's unified inboxes survive a restore (#265): their names, icons and
 * order, Other, and which inbox each account was put in — the accounts named
 * by the ids the restore gave them, not the ones they had on the old install.
 */
final class ConfigBackupUnifiedInboxesTest extends KernelTestCase
{
    private const string EMAIL = 'inboxes@backup-unified.test';

    private const string OTHER_KEY = 'YW5vdGhlciBpbnN0YWxsYXRpb25zIGtleSwgMzIgYnk=';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private ConfigBackupExporter $exporter;
    private ConfigBackupImporter $importer;
    private Encryptor $originalEncryptor;

    protected function setUp(): void
    {
        self::bootKernel();

        $container               = static::getContainer();
        $this->entityManager     = $container->get(EntityManagerInterface::class);
        $this->exporter          = $container->get(ConfigBackupExporter::class);
        $this->importer          = $container->get(ConfigBackupImporter::class);
        $this->originalEncryptor = $container->get(Encryptor::class);
        $this->connection        = $this->entityManager->getConnection();

        $this->connection->beginTransaction();

        $this->emptyTheInstall();
    }

    protected function tearDown(): void
    {
        EncryptedStringType::setEncryptor($this->originalEncryptor);

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheInboxesAndWhereEachAccountIsComeBack(): void
    {
        $sourceAccountIds = $this->seedReader();

        $document = $this->exporter->document();

        $this->restoreElsewhere($document);

        $user     = $this->restoredUser();
        $inboxes  = static::getContainer()->get(UnifiedInboxes::class);
        $all      = $inboxes->all($user);
        $accounts = $this->accountsByEmail($user);

        self::assertSame(['Mail', 'Work', null], array_map(static fn (UnifiedInbox $i): ?string => $i->name, $all));
        self::assertSame(['house', 'briefcase', 'boxes-stacked'], array_map(static fn (UnifiedInbox $i): string => $i->icon, $all));
        self::assertSame([false, false, true], array_map(static fn (UnifiedInbox $i): bool => $i->isOther, $all));

        [$mail, $work, $other] = $all;

        self::assertNotContains($accounts['work@example.test']->id, $sourceAccountIds, 'void unless the restore minted new account ids');

        self::assertSame([$accounts['home@example.test']->id], $inboxes->accountIdsOf($user, $mail), 'never moved: in the first inbox by rule');
        self::assertSame([$accounts['work@example.test']->id], $inboxes->accountIdsOf($user, $work));
        self::assertSame([$accounts['old@example.test']->id], $inboxes->accountIdsOf($user, $other));
    }

    /**
     * Every backup written before #265 has no such section: the person comes
     * back with nothing configured, and gets the starting rows, every account
     * in one Inbox.
     */
    public function testABackupWithoutInboxesRestoresOneInboxOverEverything(): void
    {
        $this->seedReader();

        $document = $this->exporter->document();
        unset($document['users'][self::EMAIL]['unifiedInboxes']);

        $this->restoreElsewhere($document);

        $user    = $this->restoredUser();
        $inboxes = static::getContainer()->get(UnifiedInboxes::class);

        self::assertCount(2, $inboxes->all($user));
        self::assertCount(3, $inboxes->accountIdsOf($user, $inboxes->first($user)));
    }

    /**
     * A hand-edited file is not trusted: an overlong name is cut, an unknown
     * icon is the tray, an account id the file does not carry is dropped, an
     * account named twice stays in the first inbox naming it, and a second
     * Other comes back as an ordinary inbox instead of aborting the restore.
     */
    public function testAHandEditedSectionIsReadDefensively(): void
    {
        $this->seedReader();

        $document = $this->exporter->document();
        $file     = array_column($document['users'][self::EMAIL]['accounts'], 'id', 'email');

        $document['users'][self::EMAIL]['unifiedInboxes'] = [
            ['name' => str_repeat('x', 60), 'icon' => 'not-an-icon', 'isOther' => false, 'accountIds' => [$file['work@example.test'], 987654]],
            ['name' => 'Again', 'icon' => 'star', 'isOther' => true, 'accountIds' => [$file['work@example.test'], $file['old@example.test']]],
            ['name' => null, 'icon' => 'bell', 'isOther' => true, 'accountIds' => []],
        ];

        $this->restoreElsewhere($document);

        $user     = $this->restoredUser();
        $inboxes  = static::getContainer()->get(UnifiedInboxes::class);
        $all      = $inboxes->all($user);
        $accounts = $this->accountsByEmail($user);

        self::assertSame(UnifiedInbox::NAME_MAX_LENGTH, mb_strlen((string) $all[0]->name));
        self::assertSame('inbox', $all[0]->icon);
        self::assertSame([true, false], [$all[1]->isOther, $all[2]->isOther], 'one Other, the first the file names');
        // Work as assigned, and home, never moved, by rule.
        self::assertEqualsCanonicalizing(
            [$accounts['home@example.test']->id, $accounts['work@example.test']->id],
            $inboxes->accountIdsOf($user, $all[0]),
        );
        self::assertSame([$accounts['old@example.test']->id], $inboxes->accountIdsOf($user, $all[1]));
    }

    /** @return list<int> the source accounts' ids */
    private function seedReader(): array
    {
        $user            = new User();
        $user->email     = self::EMAIL;
        $user->nameFirst = 'Inboxes';
        $user->nameLast  = 'Backup';
        $user->password  = '$2y$13$0123456789012345678901uJ7yCgMbNoMYVUFsyDhV0EjJb9dRSkq';
        $user->roles     = ['ROLE_USER'];
        $this->entityManager->persist($user);

        $made = [];

        foreach (['home@example.test', 'work@example.test', 'old@example.test'] as $order => $email) {
            $account                 = new Account();
            $account->usr            = $user;
            $account->name           = $email;
            $account->email          = $email;
            $account->username       = $email;
            $account->sortOrder      = $order;
            $account->imapHost       = 'imap.example.test';
            $account->imapPort       = 993;
            $account->imapEncryption = 'ssl';
            $account->authType       = 'password';
            $account->isActive       = true;
            $this->entityManager->persist($account);

            $made[$email] = $account;
        }

        $this->entityManager->flush();

        $inboxes = static::getContainer()->get(UnifiedInboxes::class);
        $first   = $inboxes->first($user);

        $inboxes->update($user, $first, 'Mail', 'house');
        $work = $inboxes->create($user, 'Work', 'briefcase');
        $inboxes->assign($user, $made['work@example.test'], $work);
        $inboxes->assign($user, $made['old@example.test'], $inboxes->other($user));

        return array_map(static fn (Account $account): int => (int) $account->id, array_values($made));
    }

    /** @param array<string, mixed> $document */
    private function restoreElsewhere(array $document): void
    {
        EncryptedStringType::setEncryptor(new Encryptor(self::OTHER_KEY));

        $this->emptyTheInstall();

        // Ids the source install used are taken, so the restored rows cannot
        // land on them by coincidence and pass a remap that never happened.
        $this->connection->executeStatement("SELECT setval(pg_get_serial_sequence('account', 'id'), 900000)");

        static::getContainer()->get(UnifiedInboxes::class)->reset();

        $this->importer->apply($document);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function restoredUser(): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return array<string, Account> */
    private function accountsByEmail(User $user): array
    {
        $byEmail = [];

        foreach (static::getContainer()->get(AccountRepository::class)->findForUserOrdered($user) as $account) {
            $byEmail[(string) $account->email] = $account;
        }

        return $byEmail;
    }

    private function emptyTheInstall(): void
    {
        $tables = array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', implode(', ', $tables)));
        $this->entityManager->clear();
    }
}
