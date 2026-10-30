<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\MakeLabelFolderHandler;
use App\Infrastructure\Messaging\Message\MakeLabelFolderMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Imap\ImapFolderManager;
use App\Service\Job\JobNotifier;
use App\Service\Label\LabelFolderMaker;
use App\Service\Label\LabelNotifier;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A label becoming an Outlook folder: the folder, then its mail, then the
 * category (#221). Graph is a mock that keeps what it was asked.
 */
final class LabelFolderMakerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => 'e2e-admin@plmail.test']);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnOutlookFolderIsCreatedInsideItsParentAndBound(): void
    {
        $account = $this->microsoft();
        $parent  = $this->label('Maker Parent');
        $child   = $this->label('Maker Child', $parent);

        $this->maker(['{"id":"folder-parent"}', '{"id":"folder-child"}'])->make($child, $account);

        self::assertSame([
            'POST /v1.0/me/mailFolders {"displayName":"Maker Parent"}',
            'POST /v1.0/me/mailFolders/folder-parent/childFolders {"displayName":"Maker Child"}',
        ], $this->requests);
        self::assertSame('folder-child', $child->bindingFor($account)?->graphFolderId);
    }

    public function testAFolderAlreadyThereIsNotMadeAgain(): void
    {
        $account = $this->microsoft();
        $label   = $this->label('Maker Twice');
        $maker   = $this->maker(['{"id":"folder-once"}']);

        $maker->make($label, $account);
        $maker->make($label, $account);

        self::assertCount(1, $this->requests);
        self::assertFalse($maker->canMake($label, $account));
    }

    public function testGmailHasNoFolderToMake(): void
    {
        $account = $this->microsoft();
        $account->oauthProvider = 'google';

        self::assertFalse($this->maker([])->canMake($this->label('Maker Gmail'), $account));
    }

    public function testTheMailMovesAndTheCategoryGoes(): void
    {
        $account  = $this->microsoft();
        $label    = $this->label('Maker Mail');
        $resolver = self::getContainer()->get(LabelResolver::class);
        $inbox    = $resolver->systemLabel(LabelRole::Inbox, $account);

        $binding = $resolver->binding($label, $account);
        $binding->graphFolderId   = 'folder-mail';
        $binding->graphCategoryId = 'category-mail';

        $message = $this->message($account);
        $message->addLabel($label);
        $message->addLabel($inbox);

        $job       = new BackgroundJob($this->user, JobKind::MoveTo);
        $job->view = ['scope' => 'label', 'value' => (string) $label->id, 'unreadOnly' => false, 'labelId' => $label->id, 'accountId' => $account->id];
        $this->em->persist($job);
        $this->em->flush();

        $labelId   = $label->id;
        $messageId = $message->id;
        $jobId     = (int) $job->id;
        $accountId = $account->id;

        $this->handler()(new MakeLabelFolderMessage($jobId));

        $this->em->clear();

        $labels = $this->em->find(Message::class, $messageId)->labels->map(static fn (Label $l): ?int => $l->id)->toArray();

        self::assertContains($labelId, $labels);
        self::assertNotContains($inbox->id, $labels, 'it left the inbox');
        self::assertSame(JobState::Done, $this->em->find(BackgroundJob::class, $jobId)?->state);
        self::assertSame(['DELETE /v1.0/me/outlook/masterCategories/category-mail '], $this->requests);

        $account = $this->em->find(Account::class, $accountId);
        self::assertNull($this->em->find(Label::class, $labelId)?->bindingFor($account)?->graphCategoryId);
    }

    /** An Outlook folder is a location: moving out of one takes its label off. */
    public function testMovingBetweenOutlookFoldersLeavesOneLabel(): void
    {
        $account  = $this->microsoft();
        $from     = $this->label('Maker From');
        $to       = $this->label('Maker To');
        $resolver = self::getContainer()->get(LabelResolver::class);

        $resolver->binding($from, $account)->graphFolderId = 'folder-from';
        $resolver->binding($to, $account)->graphFolderId   = 'folder-to';

        $message = $this->message($account);
        $message->addLabel($from);
        $this->em->flush();

        self::getContainer()->get(ThreadStatusUpdater::class)->move([$message], $to);

        self::assertFalse($message->labels->contains($from));
        self::assertTrue($message->labels->contains($to));
    }

    private function message(Account $account): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'maker';
        $thread->normalizedSubject = 'maker';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new \DateTimeImmutable();
        $thread->category          = MessageCategory::Primary;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->messageId      = '<maker-' . uniqid('', true) . '@example.test>';
        $message->subject        = 'maker';
        $message->hasAttachments = false;
        $this->em->persist($message);

        return $message;
    }

    /** @param list<string> $answers */
    private function graph(array $answers): GraphApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->requests[] = $method . ' ' . parse_url($url, PHP_URL_PATH) . ' ' . ($options['body'] ?? '');

            return new MockResponse(array_shift($answers) ?? '{}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        return new GraphApiClient($http, self::getContainer()->get(OAuthTokenManager::class));
    }

    /** @param list<string> $answers */
    private function maker(array $answers): LabelFolderMaker
    {
        $container = self::getContainer();

        return new LabelFolderMaker(
            $container->get(ImapFolderManager::class),
            $this->graph($answers),
            $container->get(LabelResolver::class),
            $this->em,
        );
    }

    private function handler(): MakeLabelFolderHandler
    {
        $container = self::getContainer();

        return new MakeLabelFolderHandler(
            $container->get(BackgroundJobRepository::class),
            $container->get(LabelRepository::class),
            $container->get(AccountRepository::class),
            $container->get(MessageRepository::class),
            $container->get(ThreadStatusUpdater::class),
            $this->graph([]),
            $container->get(LabelNotifier::class),
            $container->get(JobNotifier::class),
            $container->get(UrlGeneratorInterface::class),
            $this->em,
            new NullLogger(),
        );
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

    private function microsoft(): Account
    {
        $account                   = new Account();
        $account->usr              = $this->user;
        $account->email            = 'maker-' . uniqid('', true) . '@example.test';
        $account->username         = $account->email;
        $account->imapHost         = 'outlook.office365.com';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'x';
        $account->oauthTokenExpiry = new \DateTimeImmutable('+1 day');
        $account->isActive         = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
