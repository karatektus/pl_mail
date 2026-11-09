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
use App\Repository\Mail\Message\MessageRepository;
use App\Repository\Mail\Message\MessageLabelRepository;
use App\Repository\User\User\UserRepository;
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

    /**
     * The dialog asks where the folder goes, starts on the place chosen last
     * time on the account, makes it there and remembers the choice (#221).
     */
    public function testTheFolderGoesWhereThePersonPicksAndThePlaceIsRemembered(): void
    {
        $account = $this->imap('make-placed');
        $inbox   = $this->folderAt($account, 'INBOX');
        $clients = $this->folderAt($account, 'INBOX.Clients');
        $label   = $this->label('Make Placed');

        $account->newFolderParentPath = 'INBOX.Clients';
        $this->em->flush();

        $this->server = new FakeFolderServer(static::getContainer()->get(ImapLoop::class));
        $this->server->pointAt($account);
        $this->em->flush();

        $url     = '/labels/' . $label->id . '/folder-on/' . $account->id;
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame((string) $clients->id, $crawler->filter('select[data-folder-place] option[selected]')->attr('value'), 'the last choice');

        $token = (string) $crawler->filter('form input[name=_token]')->attr('value');
        $this->client->request('POST', $url, ['_token' => $token, 'parent' => (string) $inbox->id]);

        self::assertResponseIsSuccessful();
        self::assertSame('INBOX.Make Placed', $this->folderOn($label, $account));
        self::assertSame('INBOX', $this->em->getRepository(Account::class)->find($account->id)?->newFolderParentPath);
    }

    /** No picker where the parent label is a folder already: it goes inside it. */
    public function testALabelUnderAFolderSaysWhereItGoesInsteadOfAsking(): void
    {
        $account = $this->imap('make-inside');
        $parent  = $this->label('Make Inside Parent');
        $child   = $this->label('Make Inside Child', $parent);
        $this->makeFolderOn($parent, $account);

        $crawler = $this->client->request('GET', '/labels/' . $child->id . '/folder-on/' . $account->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[data-folder-place]'));
        self::assertStringContainsString('Make Inside Parent', $crawler->filter('[data-label-make-folder-inside]')->text());
    }

    public function testAPlaceOnAnotherAccountIsRefused(): void
    {
        $account = $this->imap('make-place-mine');
        $other   = $this->imap('make-place-other');
        $foreign = $this->folderAt($other, 'INBOX');
        $label   = $this->label('Make Foreign Place');

        $url     = '/labels/' . $label->id . '/folder-on/' . $account->id;
        $crawler = $this->client->request('GET', $url);
        $token   = (string) $crawler->filter('form input[name=_token]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'parent' => (string) $foreign->id]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->folderOn($label, $account));
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

        $messageLabelRepository = static::getContainer()->get(MessageLabelRepository::class);

        self::assertSame(1, $messageLabelRepository->countToFileIntoFolder((int) $account->id, (int) $label->id));
        self::assertSame([$inInbox->id], $messageLabelRepository->findIdsToFileIntoFolder((int) $account->id, (int) $label->id));

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

    private function folderAt(Account $account, string $path): \App\Entity\Mail\Mailbox
    {
        $mailbox = new \App\Entity\Mail\Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = $path;
        $mailbox->fullPath      = $path;
        $mailbox->delimiter     = '.';
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
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
