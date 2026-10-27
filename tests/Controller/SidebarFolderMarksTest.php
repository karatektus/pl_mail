<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Account\AuthType;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sidebar tells a folder from a label (#221).
 *
 * - under LABELS, a label that is a folder on a plain IMAP account wears a
 *   folder and names the account; a label that is not keeps its dot, and so
 *   does one that is "a folder" only on Gmail, where a label is a label.
 */
final class SidebarFolderMarksTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFolderIsMarkedAndAPlainLabelIsNot(): void
    {
        $imap  = $this->account('folder-marks-imap');
        $gmail = $this->account('folder-marks-gmail', gmail: true);

        $folder   = $this->label('Folder Marks Folder');
        $tag      = $this->label('Folder Marks Tag');
        $gmailish = $this->label('Folder Marks Gmail');

        $this->bind($folder, $imap, 'Folder Marks Folder');
        $this->bind($gmailish, $gmail, 'Folder Marks Gmail');

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $markOf = static fn (string $name) => $crawler
            ->filter('#label-list .nav-item')
            ->reduce(static fn ($row): bool => str_contains($row->text(), $name))
            ->first()
            ->filter('[data-label-folder]');

        self::assertCount(1, $markOf('Folder Marks Folder'), 'a folder on an IMAP account is marked');
        self::assertStringContainsString($imap->displayAddress, (string) $markOf('Folder Marks Folder')->attr('title'));
        self::assertCount(0, $markOf('Folder Marks Tag'), 'a plain label keeps its dot');
        self::assertCount(0, $markOf('Folder Marks Gmail'), 'Gmail is untouched');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function account(string $name, bool $gmail = false): Account
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = $name . '-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = $gmail ? null : 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = $gmail ? AuthType::OAuth2->value : 'password';
        $account->oauthProvider  = $gmail ? MailProvider::Google->value : null;
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function label(string $name): Label
    {
        $label = new Label();
        $label->usr  = $this->user;
        $label->name = $name;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function bind(Label $label, Account $account, string $path): void
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = $path;
        $mailbox->fullPath      = $path;
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);

        $binding = new LabelBinding();
        $binding->label   = $label;
        $binding->account = $account;
        $binding->mailbox = $mailbox;
        $this->em->persist($binding);
        $this->em->flush();
    }
}
