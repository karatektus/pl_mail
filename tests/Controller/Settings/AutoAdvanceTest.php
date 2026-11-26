<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\AutoAdvance;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Where the reading pane goes after the open conversation leaves the list.
 *
 * The moving is the browser's — tests/e2e/auto-advance.spec.ts. What is the
 * server's is the choice: stored, defaulting to the list, and handed to the
 * pane on <body>.
 */
final class AutoAdvanceTest extends WebTestCase
{
    private const string EMAIL = 'auto-advance@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = self::EMAIL;
        $user->nameFirst = 'Auto';
        $user->nameLast  = 'Advance';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSomebodyWhoHasNeverChosenGoesBackToTheList(): void
    {
        self::assertSame(AutoAdvance::List, $this->reread()->autoAdvance);
        self::assertSame('list', $this->settings()->filter('body')->attr('data-mail--mail-pane-advance-value'));
        self::assertCount(1, $this->settings()->filter('input[name="advance"][value="list"][checked]'));
    }

    public function testTheChoiceIsStoredAndHandedToThePane(): void
    {
        $this->post(['advance' => 'next']);

        self::assertResponseRedirects('/settings/layout');
        self::assertSame(AutoAdvance::Next, $this->reread()->autoAdvance);
        self::assertSame('next', $this->settings()->filter('body')->attr('data-mail--mail-pane-advance-value'));

        $this->post(['advance' => 'previous']);

        self::assertSame(AutoAdvance::Previous, $this->reread()->autoAdvance);
    }

    public function testBackToTheListIsTheAbsentKeyAgain(): void
    {
        $this->post(['advance' => 'next']);
        $this->post(['advance' => 'list']);

        self::assertSame(AutoAdvance::List, $this->reread()->autoAdvance);
        self::assertNull($this->reread()->getSetting(User::SETTING_AUTO_ADVANCE));
    }

    public function testAnUnknownChoiceIsReadAsTheList(): void
    {
        $this->post(['advance' => 'next']);
        $this->post(['advance' => 'sideways']);

        self::assertSame(AutoAdvance::List, $this->reread()->autoAdvance);
    }

    public function testAPostWithoutTheFieldChangesNothing(): void
    {
        $this->post(['advance' => 'next']);
        $this->post([]);

        self::assertSame(AutoAdvance::Next, $this->reread()->autoAdvance);
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/auto-advance', ['advance' => 'next']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(AutoAdvance::List, $this->reread()->autoAdvance);
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $token = (string) $this->settings()
            ->filter('form[action="/settings/auto-advance"] input[name="_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/settings/auto-advance', ['_token' => $token, ...$fields]);
    }

    private function settings(): Crawler
    {
        return $this->client->request('GET', '/settings/layout');
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
