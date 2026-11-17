<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Inbox → Message list → Pinned conversations (#654).
 *
 * The defaults are the claim worth holding (twenty, forever, keep on move),
 * then that each field saves, and that "a number of days" picked from the
 * select comes back as a day count the page can draw rather than a preset it
 * would draw as something else.
 */
final class PinSettingsTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDefaultsAreTwentyForeverAndKeep(): void
    {
        self::assertSame(User::PIN_REMINDER_DEFAULT, $this->user->pinReminderAt);
        self::assertSame(20, $this->user->pinReminderAt);
        self::assertNull($this->user->pinDurationDays);
        self::assertTrue($this->user->pinKeepOnMove);
    }

    public function testTheCardIsOnTheMessageListPage(): void
    {
        $crawler = $this->client->request('GET', '/settings/message-list');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('#card-pinning input[name="reminderAt"]'));
    }

    public function testEachFieldSaves(): void
    {
        $this->save(['reminderAt' => '5', 'duration' => '14', 'keepOnMove' => '0']);

        $user = $this->reload();
        self::assertSame(5, $user->pinReminderAt);
        self::assertSame(14, $user->pinDurationDays);
        self::assertFalse($user->pinKeepOnMove);

        $this->save(['reminderAt' => '0', 'duration' => '']);

        $user = $this->reload();
        self::assertSame(0, $user->pinReminderAt, '0 is off, not the default');
        self::assertNull($user->pinDurationDays, 'empty is forever');
    }

    /** Picked from the select, "a number of days" must not land on a preset. */
    public function testChoosingANumberOfDaysStartsAtOneThatIsNotAPreset(): void
    {
        $this->save(['duration' => '7']);
        $this->save(['duration' => 'custom']);

        self::assertSame(10, $this->reload()->pinDurationDays);

        $this->save(['duration' => 'custom', 'durationDays' => '45']);

        self::assertSame(45, $this->reload()->pinDurationDays);
    }

    /** @param array<string, string> $fields */
    private function save(array $fields): void
    {
        $crawler = $this->client->request('GET', '/settings/message-list');
        $token   = (string) $crawler->filter('#card-pinning input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/pinning', $fields + ['_token' => $token]);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
    }

    private function reload(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id) ?? self::fail('user gone');
    }
}
