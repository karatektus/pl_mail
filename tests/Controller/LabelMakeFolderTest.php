<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Label\LabelResolver;
use App\Tests\Service\Imap\FakeFolderServer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Make it a folder, from the label dialog: on the account asked about and no
 * other, and refused where there is nothing to make (#221).
 */
final class LabelMakeFolderTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private ?FakeFolderServer $server = null;

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
        $this->server?->stop();

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDialogOffersItOnlyWhereTheLabelIsNotAFolderYet(): void
    {
        $tag    = $this->imap('make-tag');
        $folder = $this->imap('make-folder');
        $label  = $this->label('Make Fixture');

        $this->labelResolver()->binding($label, $folder)->graphFolderId = null;
        $this->em->flush();
        $this->makeFolderOn($label, $folder);

        $crawler = $this->client->request('GET', '/labels/' . $label->id . '/edit');

        self::assertResponseIsSuccessful();

        $hrefs = $crawler->filter('[data-label-make-folder-link]')->each(static fn ($a): string => (string) $a->attr('href'));

        self::assertContains('/labels/' . $label->id . '/folder-on/' . $tag->id, $hrefs);
        self::assertNotContains('/labels/' . $label->id . '/folder-on/' . $folder->id, $hrefs);
    }

    public function testMakingItCreatesTheFolderOnThatAccountAlone(): void
    {
        $account = $this->imap('make-one');
        $other   = $this->imap('make-other');
        $label   = $this->label('Make One');

        $this->post($label, $account);

        self::assertResponseIsSuccessful();
        self::assertContains('CREATE Make One', $this->server->commands);
        self::assertNotNull($this->folderOn($label, $account));
        self::assertNull($this->folderOn($label, $other));
    }

    public function testAParentBecomesAFolderFirst(): void
    {
        $account = $this->imap('make-nested');
        $parent  = $this->label('Make Parent');
        $child   = $this->label('Make Child', $parent);

        $this->post($child, $account);

        self::assertResponseIsSuccessful();

        $creates = array_values(array_filter($this->server->commands, static fn (string $c): bool => str_starts_with($c, 'CREATE')));

        self::assertSame(['CREATE Make Parent', 'CREATE Make Parent.Make Child'], $creates);
        self::assertSame('Make Parent.Make Child', $this->folderOn($child, $account));
    }

    public function testARefusalStaysInTheDialogWithTheReason(): void
    {
        $account = $this->imap('make-refused');
        $label   = $this->label('Make Refused');

        $this->post($label, $account, refuse: 'CREATE');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Permission denied', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->folderOn($label, $account));
    }

    public function testAnotherUsersAccountIsRefused(): void
    {
        $label = $this->label('Make Foreign');

        $owner = new User();
        $owner->email    = 'make-foreign-' . uniqid('', true) . '@example.test';
        $owner->password = 'x';
        $owner->nameFirst = 'Foreign';
        $owner->nameLast  = 'Owner';
        $this->em->persist($owner);

        $foreign = $this->imap('make-foreign', $owner);

        $this->client->request('GET', '/labels/' . $label->id . '/folder-on/' . $foreign->id);

        self::assertResponseStatusCodeSame(403);
    }

    public function testASystemLabelHasNothingToMake(): void
    {
        $account = $this->imap('make-system');
        $inbox   = $this->labelResolver()->systemLabel(\App\Domain\Enum\Mail\LabelRole::Inbox, $account);

        $this->client->request('GET', '/labels/' . $inbox->id . '/folder-on/' . $account->id);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * What the job takes in: the label's mail on this account, without what is
     * in the bin, and a job only when there is some.
     */
    public function testMailWearingTheLabelIsCountedAndAJobStarts(): void
    {
        $account = $this->imap('make-mail');
        $label   = $this->label('Make Mail');
        $resolver = $this->labelResolver();

        $inInbox = $this->message($account, 'keep');
        $inInbox->addLabel($label);
        $inInbox->addLabel($resolver->systemLabel(\App\Domain\Enum\Mail\LabelRole::Inbox, $account));

        $inTrash = $this->message($account, 'bin');
        $inTrash->addLabel($label);
        $inTrash->addLabel($resolver->systemLabel(\App\Domain\Enum\Mail\LabelRole::Trash, $account));

        $this->em->flush();

        $repository = static::getContainer()->get(MessageRepository::class);

        self::assertSame(1, $repository->countToFileIntoFolder((int) $account->id, (int) $label->id));
        self::assertSame([$inInbox->id], $repository->findIdsToFileIntoFolder((int) $account->id, (int) $label->id));

        $this->post($label, $account);

        self::assertResponseIsSuccessful();

        $job = $this->em->getRepository(BackgroundJob::class)->findOneBy(['usr' => $this->user], ['id' => 'DESC']);

        self::assertSame(JobKind::MoveTo, $job?->kind);
        self::assertSame($account->id, $job->view['accountId'] ?? null);
    }

    public function testAFolderAsksWhereItsMailGoesAndStartsAJob(): void
    {
        $account  = $this->imap('tag-mail');
        $other    = $this->imap('tag-other');
        $label    = $this->label('Tag Mail');
        $sibling  = $this->label('Tag Sibling');
        $foreign  = $this->label('Tag Foreign');
        $this->makeFolderOn($label, $account);
        $this->makeFolderOn($sibling, $account);
        $this->makeFolderOn($foreign, $other);

        $message = $this->message($account, 'tag');
        $message->addLabel($label);
        $this->em->flush();

        $url     = '/labels/' . $label->id . '/tag-on/' . $account->id;
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter('[data-label-make-tag-count]')->attr('data-label-make-tag-count'));

        $values = $crawler->filter('select option')->each(static fn ($o): string => (string) $o->attr('value'));

        self::assertContains('label:' . $sibling->id, $values);
        self::assertNotContains('label:' . $foreign->id, $values, 'a folder on another account is no place for this mail');

        $token = (string) $crawler->filter('form input[name=_token]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', $url, ['_token' => $token, 'label_delete' => ['destination' => 'label:' . $sibling->id]]);
        self::assertResponseIsSuccessful();

        $job = $this->em->getRepository(BackgroundJob::class)->findOneBy(['usr' => $this->user->id], ['id' => 'DESC']);

        self::assertSame($sibling->id, $job?->view['destinationId'] ?? null);
    }

    public function testATagHasNoWayBackToOffer(): void
    {
        $account = $this->imap('tag-none');
        $label   = $this->label('Tag None');

        $this->client->request('GET', '/labels/' . $label->id . '/tag-on/' . $account->id);

        self::assertResponseStatusCodeSame(404);
    }

    private function post(Label $label, Account $account, ?string $refuse = null): void
    {
        $this->server         = new FakeFolderServer(static::getContainer()->get(ImapLoop::class));
        $this->server->refuse = $refuse;
        $this->server->pointAt($account);
        $this->em->flush();

        $url     = '/labels/' . $label->id . '/folder-on/' . $account->id;
        $crawler = $this->client->request('GET', $url);
        $token   = (string) $crawler->filter('form input[name=_token]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token]);
    }

    /** Read from the database: the request has cleared what this test held. */
    private function folderOn(Label $label, Account $account): ?string
    {
        $path = $this->connection->fetchOne(
            'SELECT mb.full_path FROM label_binding b JOIN mailbox mb ON mb.id = b.mailbox_id WHERE b.label_id = ? AND b.account_id = ?',
            [$label->id, $account->id],
        );

        return false === $path ? null : (string) $path;
    }

    private function makeFolderOn(Label $label, Account $account): void
    {
        $mailbox = new \App\Entity\Mail\Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = (string) $label->name;
        $mailbox->fullPath      = (string) $label->name;
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $mailbox->importFloorUid = 0;
        $this->em->persist($mailbox);

        $binding = $this->labelResolver()->binding($label, $account);
        $binding->mailbox      = $mailbox;
        $mailbox->labelBinding = $binding;
        $this->em->flush();
    }

    private function labelResolver(): LabelResolver
    {
        return static::getContainer()->get(LabelResolver::class);
    }

    private function label(string $name, ?Label $parent = null): Label
    {
        $label         = new Label();
        $label->usr    = $this->user;
        $label->name   = $name;
        $label->parent = $parent;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function message(Account $account, string $id): Message
    {
        $message = new Message();
        $message->account   = $account;
        $message->messageId = '<' . $id . '-' . uniqid('', true) . '@example.test>';
        $message->subject   = $id;
        $message->hasAttachments = false;
        $this->em->persist($message);

        return $message;
    }

    private function imap(string $name, ?User $owner = null): Account
    {
        $account = new Account();
        $account->usr            = $owner ?? $this->user;
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
        $this->em->persist($account);
        $this->em->flush();

        ($owner ?? $this->user)->addAccount($account);
        $this->em->flush();

        return $account;
    }
}
