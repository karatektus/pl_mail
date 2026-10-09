<?php

declare(strict_types=1);

namespace App\Tests\Service\Template;

use App\Domain\DTO\Template\TemplateLocation;
use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Mail\UserAccounts;
use App\Service\Template\TemplateLibrary;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The template tree, and what happens to templates when the folder or the
 * account around them goes away.
 *
 * The promise under test is that NOTHING THE USER WROTE IS DELETED BY DELETING
 * SOMETHING ELSE. A folder cascades away with its account and its parent; a
 * template only loses its place. Both halves are foreign keys, so the test
 * deletes through the database the way the application does and reads back
 * what is left, rather than trusting the mapping to say what the schema does.
 */
final class TemplateLibraryTest extends KernelTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string OTHER_EMAIL = 'e2e@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private TemplateLibrary $library;
    private User $user;

    protected function setUp(): void
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->library    = $container->get(TemplateLibrary::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The mirror: an account is in the tree because it exists, not because
     * anything was created for it.
     */
    public function testEveryMailAccountIsAFolderWithoutAnythingBeingStored(): void
    {
        $account = $this->account('mirror@plmail.test');

        $names = array_map(
            static fn ($node): string => $node->name,
            $this->library->tree($this->user)->accounts,
        );

        self::assertContains('mirror@plmail.test', $names);
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM template_folder WHERE account_id = ?', [$account->id]),
            'no folder row stands in for the account',
        );
    }

    public function testTheTreePutsEachTemplateWhereItWasFiled(): void
    {
        $account  = $this->account('filed@plmail.test');
        $invoices = $this->library->createFolder($this->user, 'Invoices', new TemplateLocation($account));
        $this->em->flush();

        $this->template('At the top', new TemplateLocation());
        $this->template('In the account', new TemplateLocation($account));
        $this->template('In the folder', new TemplateLocation($account, $invoices));

        $tree = $this->library->tree($this->user);
        $node = $this->nodeFor($tree->accounts, $account);

        self::assertContains('At the top', $this->names($tree->templates));
        self::assertSame(['In the account'], $this->names($node->templates));
        self::assertSame(['In the folder'], $this->names($node->children[0]->templates));
        self::assertSame('filed@plmail.test / Invoices', $node->children[0]->path);
        self::assertSame(2, $node->total());
    }

    /**
     * Deleting "2024" out of "Invoices" leaves its templates in "Invoices".
     * The foreign key alone would have dropped them directly under the
     * account — SET NULL clears the folder and knows nothing of its parent.
     */
    public function testDeletingAFolderMovesItsTemplatesUpToWhereTheFolderWas(): void
    {
        $account  = $this->account('nested@plmail.test');
        $invoices = $this->library->createFolder($this->user, 'Invoices', new TemplateLocation($account));
        $this->em->flush();

        $year = $this->library->createFolder($this->user, '2024', new TemplateLocation($account, $invoices));
        $this->em->flush();

        $deeper = $this->library->createFolder($this->user, 'Q1', new TemplateLocation($account, $year));
        $this->em->flush();

        $direct = $this->template('Directly inside', new TemplateLocation($account, $year));
        $nested = $this->template('One level further down', new TemplateLocation($account, $deeper));
        $yearId = $year->id;
        $deeperId = $deeper->id;

        $this->library->deleteFolder($year);
        $this->em->flush();
        $this->em->clear();

        foreach ([$direct->id, $nested->id] as $id) {
            $template = $this->em->find(MailTemplate::class, $id);

            self::assertNotNull($template, 'the template is kept');
            self::assertSame($invoices->id, $template->folder?->id, 'and sits in the folder the deleted one was in');
            self::assertSame($account->id, $template->account?->id);
        }

        self::assertNull($this->em->find(TemplateFolder::class, $yearId));
        self::assertNull($this->em->find(TemplateFolder::class, $deeperId), 'a folder inside the deleted one goes with it');
    }

    /**
     * Removing a mail account removes its folders and KEEPS its templates,
     * which land at the top level.
     */
    public function testRemovingAnAccountKeepsItsTemplatesAtTheTopLevel(): void
    {
        $account = $this->account('leaving@plmail.test');
        $folder  = $this->library->createFolder($this->user, 'Support', new TemplateLocation($account));
        $this->em->flush();

        $inAccount = $this->template('Filed under the account', new TemplateLocation($account));
        $inFolder  = $this->template('Filed in its folder', new TemplateLocation($account, $folder));
        $folderId  = $folder->id;

        // Straight to the database: this is about what the foreign keys do,
        // and the application's own account removal ends in the same DELETE.
        $this->connection->executeStatement('DELETE FROM account WHERE id = ?', [$account->id]);
        $this->em->clear();
        static::getContainer()->get(UserAccounts::class)->reset();

        foreach ([$inAccount->id, $inFolder->id] as $id) {
            $template = $this->em->find(MailTemplate::class, $id);

            self::assertNotNull($template, 'the template outlives the account');
            self::assertNull($template->account);
            self::assertNull($template->folder);
        }

        self::assertNull($this->em->find(TemplateFolder::class, $folderId));
    }

    /**
     * A place arrives as a key out of a form. One naming somebody else's
     * account or folder is refused — not quietly filed at the top level.
     */
    public function testAKeyNamingSomebodyElsesPlaceLocatesNothing(): void
    {
        $other = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::OTHER_EMAIL]);

        if (null === $other) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $theirAccount = $this->account('theirs@plmail.test', $other);
        $theirFolder  = $this->library->createFolder($other, 'Theirs', new TemplateLocation());
        $this->em->flush();

        self::assertNull($this->library->locate($this->user, sprintf('account:%d', $theirAccount->id)));
        self::assertNull($this->library->locate($this->user, sprintf('folder:%d', $theirFolder->id)));
        self::assertNull($this->library->locate($this->user, 'somewhere:1'));
        self::assertNotNull($this->library->locate($this->user, 'root'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<MailTemplate> $templates
     *
     * @return list<string>
     */
    private function names(array $templates): array
    {
        return array_map(static fn (MailTemplate $template): string => $template->name, $templates);
    }

    private function nodeFor(array $nodes, Account $account): object
    {
        foreach ($nodes as $node) {
            if ($node->location->account === $account) {
                return $node;
            }
        }

        self::fail('the account has a folder in the tree');
    }

    private function template(string $name, TemplateLocation $location): MailTemplate
    {
        $template       = new MailTemplate();
        $template->usr  = $this->user;
        $template->name = $name;
        $template->body = '<p>Hello</p>';

        $this->library->place($template, $location);
        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    private function account(string $email, ?User $owner = null): Account
    {
        $account = new Account();

        $account->usr       = $owner ?? $this->user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = false;
        $account->sortOrder = 50;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        // The library reads accounts through a per-request memo, and this
        // "request" keeps adding to them.
        static::getContainer()->get(UserAccounts::class)->reset();

        return $account;
    }
}
