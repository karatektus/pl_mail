<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\MailboxSpecialUse;
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
 * The label dialog says where the label is kept on each IMAP account — a
 * folder, a keyword, or plMail's alone, and why (#221 step 2).
 */
final class LabelPlacesTest extends WebTestCase
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

    public function testEachImapAccountSaysWhereTheLabelIs(): void
    {
        $off      = $this->account('places-off', keywords: false, allows: true);
        $keyword  = $this->account('places-kw', keywords: true, allows: true);
        $refused  = $this->account('places-no', keywords: true, allows: false);
        $folderOn = $this->account('places-folder', keywords: true, allows: true);

        $label = new Label();
        $label->usr  = $this->user;
        $label->name = 'Places Fixture';
        $this->em->persist($label);

        $mailbox = new Mailbox();
        $mailbox->account       = $folderOn;
        $mailbox->name          = 'Places Fixture';
        $mailbox->fullPath      = 'Places Fixture';
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);

        $binding = new LabelBinding();
        $binding->label   = $label;
        $binding->account = $folderOn;
        $binding->mailbox = $mailbox;
        $this->em->persist($binding);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/labels/' . $label->id . '/edit');

        self::assertResponseIsSuccessful();

        $state = static fn (Account $a): string => (string) $crawler
            ->filter('[data-label-place]')
            ->reduce(static fn ($li): bool => str_contains($li->text(), (string) $a->displayAddress))
            ->attr('data-label-place');

        self::assertSame('local_off', $state($off));
        self::assertSame('keyword', $state($keyword));
        self::assertSame('local_server', $state($refused));
        self::assertSame('folder', $state($folderOn));
    }

    private function account(string $name, bool $keywords, bool $allows): Account
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = $name . '-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $account->labelsAsKeywords = $keywords;
        $this->em->persist($account);

        $inbox = new Mailbox();
        $inbox->account         = $account;
        $inbox->name            = 'INBOX';
        $inbox->fullPath        = 'INBOX';
        $inbox->delimiter       = '/';
        $inbox->specialUse      = MailboxSpecialUse::INBOX;
        $inbox->isSyncEnabled   = true;
        $inbox->keywordsAllowed = $allows;
        $this->em->persist($inbox);
        $this->em->flush();

        $this->user->addAccount($account);
        $this->em->flush();

        return $account;
    }
}
