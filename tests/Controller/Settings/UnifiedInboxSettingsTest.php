<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\UnifiedInbox;
use App\Repository\Mail\UnifiedInbox\UnifiedInboxRepository;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Unified inboxes (#265): the page draws the inboxes and the
 * accounts, and every write goes through the reader's own rows.
 */
final class UnifiedInboxSettingsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private UnifiedInboxes $inboxes;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->inboxes    = $container->get(UnifiedInboxes::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePageDrawsTheInboxesAndTheAccounts(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=inboxes');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-unified-inbox]'), 'the first inbox and Other');
        self::assertCount(1, $crawler->filter('[data-unified-inbox-account="' . $this->account->id . '"] select option[selected]'));
        self::assertCount(24, $crawler->filter('[data-unified-inbox] [data-inbox-icon-picker]')->first()->filter('input[type=radio]'));
    }

    public function testCreateRenameAssignAndDelete(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=inboxes');
        $token   = (string) $crawler->filter('form[action="/settings/inboxes"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/inboxes', ['_token' => $token, 'name' => 'Work', 'icon' => 'briefcase']);
        self::assertResponseRedirects('/settings?section=inboxes');

        $work = $this->named('Work');

        $this->client->request('POST', '/settings/inboxes/' . $work->id, ['_token' => $token, 'name' => 'Office', 'icon' => 'building']);
        // Read again: the request has its own unit of work.
        $work = $this->named('Office');
        self::assertSame('Office', $work->name);
        self::assertSame('building', $work->icon);

        $this->client->request('POST', '/settings/inboxes/account/' . $this->account->id, ['_token' => $token, 'inbox' => (string) $work->id]);
        $this->inboxes->reset();
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $work));

        $this->client->request('POST', '/settings/inboxes/' . $work->id . '/delete', ['_token' => $token]);
        $this->inboxes->reset();
        self::assertNull($this->inboxes->find($this->user, (int) $work->id));
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $this->inboxes->other($this->user)));
    }

    public function testTheOrderIsWrittenAsOneList(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=inboxes');
        $token   = (string) $crawler->filter('[data-settings--tab-order-csrf-value]')->attr('data-settings--tab-order-csrf-value');
        $ids     = array_map(static fn (UnifiedInbox $inbox): int => (int) $inbox->id, $this->inboxes->all($this->user));

        $this->client->request(
            'POST',
            '/settings/inboxes/order',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: (string) json_encode(['keys' => array_reverse($ids), '_token' => $token]),
        );

        self::assertResponseIsSuccessful();

        $this->inboxes->reset();
        self::assertSame(array_reverse($ids), array_map(static fn (UnifiedInbox $inbox): int => (int) $inbox->id, $this->inboxes->all($this->user)));
    }

    public function testAWriteWithoutTheTokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/inboxes', ['name' => 'Work', 'icon' => 'briefcase']);

        self::assertNull($this->namedOrNull('Work'));
    }

    public function testAnotherReadersInboxIsNotFound(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=inboxes');
        $token   = (string) $crawler->filter('form[action="/settings/inboxes"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/inboxes/999999999', ['_token' => $token, 'name' => 'Mine now', 'icon' => 'inbox']);

        self::assertResponseStatusCodeSame(404);
    }

    private function named(string $name): UnifiedInbox
    {
        return $this->namedOrNull($name) ?? throw new \RuntimeException('no inbox named ' . $name);
    }

    private function namedOrNull(string $name): ?UnifiedInbox
    {
        return static::getContainer()->get(UnifiedInboxRepository::class)->findOneBy(['usr' => $this->user, 'name' => $name]);
    }
}
