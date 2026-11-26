<?php

declare(strict_types=1);

namespace App\Tests\Controller\Layout;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The workspace settings dialog (#783): what it draws, and the save of what
 * is shared between workspaces — the user's sidebars and the Mail sidebar's
 * clock. The workspace's own groups and sidebar go with Save workspace, which
 * WorkspaceShellTest covers.
 */
final class WorkspaceSettingsTest extends WebTestCase
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

    public function testTheDialogDraws(): void
    {
        $this->client->request('GET', '/layout/workspace/settings');

        self::assertResponseIsSuccessful();
    }

    public function testTheSidebarsAndTheClockAreSavedWhole(): void
    {
        $sidebars = [['id' => 'sb1', 'name' => 'Work', 'entries' => [['t' => 'head', 'text' => 'Plan'], ['t' => 'clock']]]];

        $this->save(['sidebars' => $sidebars, 'mailClock' => true], $this->token());

        self::assertResponseIsSuccessful();

        $user = $this->reread();
        self::assertEquals($sidebars, $user->getSetting(User::SETTING_SIDEBARS));
        self::assertTrue($user->mailSidebarClock);
    }

    public function testADocumentThatIsNotSidebarsIsRefusedAndNothingWritten(): void
    {
        $token = $this->token();

        foreach ([
            ['sidebars' => 'all of them'],
            ['sidebars' => [['id' => 'mail', 'name' => 'Mail', 'entries' => []]]],
            ['sidebars' => [['id' => 'sb1', 'name' => 'A', 'entries' => []], ['id' => 'sb1', 'name' => 'B', 'entries' => []]]],
        ] as $document) {
            $this->save($document, $token);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertNull($this->reread()->getSetting(User::SETTING_SIDEBARS));
    }

    public function testWithoutATokenNothingIsWritten(): void
    {
        $this->save(['sidebars' => []], null);

        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, mixed> $document */
    private function save(array $document, ?string $token): void
    {
        $this->client->request('POST', '/layout/workspace/settings', [], [], array_filter([
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]), json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function token(): string
    {
        return (string) $this->client->request('GET', '/mail/inbox')
            ->filter('[data-layout--workspace-token-value]')
            ->attr('data-layout--workspace-token-value');
    }

    private function reread(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
