<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AllMailViewTest extends WebTestCase
{
    use SeedsMarkerFixtures;
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->user = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAccountAllMailHasDescriptorAndKeepsInboxSeparate(): void
    {
        $thread = $this->thread('synthetic archived mail');
        $thread->labels->clear();
        $this->em->flush();
        $url = '/mail/account/' . $this->account->id;
        $this->client->request('GET', $url . '/all');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#inbox-list-frame[data-sync-scope="all_mail"][data-list-scope-value="' . $this->account->id . '"]');
        self::assertSelectorExists('#thread_' . $thread->id);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#thread_' . $thread->id);
        $this->client->request('GET', $url . '/all?page=999');
        self::assertResponseRedirects();
    }

    public function testArchiveAndSnoozeStayWhileTrashLeaves(): void
    {
        $thread = $this->thread('synthetic action');
        $url = '/mail/account/' . $this->account->id . '/all';
        $token = $this->client->request('GET', $url)->filter('meta[name="csrf-token"]')->attr('content');
        $descriptor = ['scope'=>'all_mail', 'value'=>(string) $this->account->id];
        foreach (['archive'=>[], 'snooze'=>['until'=>'2030-01-01T00:00:00Z']] as $action=>$payload) {
            $this->post('/status/thread/' . $thread->id . '/' . $action, $token, $descriptor + $payload);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('action="remove" target="thread_' . $thread->id, (string) $this->client->getResponse()->getContent());
            if ('archive' === $action) {
                preg_match('#/status/undo/[a-f0-9]{32}#', (string) $this->client->getResponse()->getContent(), $undo);
                self::assertNotEmpty($undo);
                $this->post($undo[0], $token, []);
                self::assertResponseIsSuccessful();
            }
            $this->client->request('GET', $url);
            self::assertSelectorExists('#thread_' . $thread->id);
        }
        $this->post('/status/bulk/archive', $token, $descriptor + ['ids'=>[$thread->id]]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('action="remove" target="thread_' . $thread->id, (string) $this->client->getResponse()->getContent());
        $this->post('/status/thread/' . $thread->id . '/trash', $token, $descriptor);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertSelectorNotExists('#thread_' . $thread->id);
    }

    public function testForeignAndMalformedAccountsAreRejected(): void
    {
        $original = $this->user;
        $this->user = $this->seedUser();
        $foreign = $this->seedAccount();
        $this->client->loginUser($original);
        $this->client->request('GET', '/mail/account/' . $foreign->id . '/all');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/mail/account/invalid/all');
        self::assertResponseStatusCodeSame(404);
    }

    private function post(string $url, string $token, array $body): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE'=>'application/json', 'HTTP_X_CSRF_TOKEN'=>$token], content: json_encode($body));
    }
}
