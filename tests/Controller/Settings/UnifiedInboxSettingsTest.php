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
 * Settings → Account groups (#265, #783): the page draws the groups and their
 * accounts, and every write goes through the reader's own rows. Other is the
 * rest: nothing is put in it or taken out by hand, and it keeps its name.
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
        $crawler = $this->client->request('GET', '/settings/inboxes');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-unified-inbox]'), 'the first inbox and Other');
        $first = $this->inboxes->first($this->user);
        $other = $this->inboxes->other($this->user);

        self::assertCount(1, $crawler->filter('[data-group-accounts="' . $first->id . '"] [data-group-account="' . $this->account->id . '"]'), 'in the first group by rule');
        self::assertCount(0, $crawler->filter('[data-group-accounts="' . $other->id . '"] select[name="account"]'), 'nothing is added to Other');
        self::assertCount(0, $crawler->filter('[data-unified-inbox="' . $other->id . '"] input[type="text"][name="name"]'), 'nor is it renamed');
        self::assertCount(24, $crawler->filter('[data-unified-inbox] [data-inbox-icon-picker]')->first()->filter('input[type=radio]'));
    }

    public function testCreateRenameAssignAndDelete(): void
    {
        $crawler = $this->client->request('GET', '/settings/inboxes');
        $token   = (string) $crawler->filter('form[action="/settings/inboxes"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/inboxes', ['_token' => $token, 'name' => 'Work', 'icon' => 'briefcase']);
        self::assertResponseRedirects('/settings/inboxes');

        $work = $this->named('Work');

        $this->client->request('POST', '/settings/inboxes/' . $work->id, ['_token' => $token, 'name' => 'Office', 'icon' => 'building']);
        // Read again: the request has its own unit of work.
        $work = $this->named('Office');
        self::assertSame('Office', $work->name);
        self::assertSame('building', $work->icon);

        // Into Work as well: the first group keeps it.
        $this->client->request('POST', '/settings/inboxes/' . $work->id . '/accounts', ['_token' => $token, 'account' => (string) $this->account->id, 'do' => 'add']);
        $this->inboxes->reset();
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $work));
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $this->inboxes->first($this->user)));

        // Out of the first: Work alone.
        $first = $this->inboxes->first($this->user);
        $this->client->request('POST', '/settings/inboxes/' . $first->id . '/accounts', ['_token' => $token, 'account' => (string) $this->account->id, 'do' => 'remove']);
        $this->inboxes->reset();
        self::assertSame([], $this->inboxes->accountIdsOf($this->user, $this->inboxes->first($this->user)));
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $work));

        // Work deleted: its last group gone, the account is the rest.
        $this->client->request('POST', '/settings/inboxes/' . $work->id . '/delete', ['_token' => $token]);
        $this->inboxes->reset();
        self::assertNull($this->inboxes->find($this->user, (int) $work->id));
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $this->inboxes->other($this->user)));
    }

    public function testOtherTakesNoAccountsByHandAndKeepsItsName(): void
    {
        $crawler = $this->client->request('GET', '/settings/inboxes');
        $token   = (string) $crawler->filter('form[action="/settings/inboxes"] input[name="_token"]')->attr('value');
        $other   = $this->inboxes->other($this->user);

        $this->client->request('POST', '/settings/inboxes/' . $other->id . '/accounts', ['_token' => $token, 'account' => (string) $this->account->id, 'do' => 'add']);
        $this->inboxes->reset();
        self::assertSame([], $this->inboxes->accountIdsOf($this->user, $this->inboxes->other($this->user)));

        $this->client->request('POST', '/settings/inboxes/' . $other->id, ['_token' => $token, 'name' => 'Leftovers', 'icon' => 'star']);
        $other = static::getContainer()->get(UnifiedInboxRepository::class)->find($other->id);
        self::assertNull($other?->name, 'Other keeps its own name');
        self::assertSame('star', $other?->icon, 'its icon is still the reader\'s');
    }

    public function testTheOrderIsWrittenAsOneList(): void
    {
        $crawler = $this->client->request('GET', '/settings/inboxes');
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
        $crawler = $this->client->request('GET', '/settings/inboxes');
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
