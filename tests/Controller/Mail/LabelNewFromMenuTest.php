<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Create new" in the Label as menu opens the label form with what was typed
 * into the menu's filter already in it. The menu's half is a browser's to
 * prove (tests/e2e/menu-filter.spec.ts); this is the form's.
 */
final class LabelNewFromMenuTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheTypedNameStartsTheForm(): void
    {
        $page = $this->client->request('GET', '/labels/new?name=%20Receipts%202026%20');

        self::assertResponseIsSuccessful();
        self::assertSame('Receipts 2026', $page->filter('input[name="label[name]"]')->attr('value'));
    }

    public function testWithoutANameTheFormStartsEmpty(): void
    {
        $page = $this->client->request('GET', '/labels/new');

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $page->filter('input[name="label[name]"]')->attr('value'));
    }
}
