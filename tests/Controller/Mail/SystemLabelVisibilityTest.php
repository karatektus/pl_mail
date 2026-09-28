<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;

/**
 * The eye toggle in label settings hides a system row, and shows it again.
 *
 * It saved the flag for every system label and the sidebar read it for Spam
 * alone, so hiding Trash greyed Trash out in the settings list and changed
 * nothing anywhere a person looks. SidebarSpamEntryTest was green throughout,
 * because it set the flag on the entity instead of pressing the toggle — so
 * this one presses the toggle, through the form the settings page renders.
 */
final class SystemLabelVisibilityTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        // Before anything else is pulled out of the container — see
        // LabelChangeReachesEveryTabTest for why it has to be first.
        $container->set(HubInterface::class, new RecordingHub());

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

    public function testHidingTrashTakesItsRowAwayAndShowingItBringsItBack(): void
    {
        $trash = $this->seedLabel('Trash', LabelRole::Trash);

        self::assertSame(1, $this->trashRowsOn('/mail/inbox'), 'shown until switched off');

        $this->toggle($trash);

        // The tab that pressed it sees the row go at once, not on its next
        // full page load: the settings page has the sidebar beside the list.
        $stream = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('target="sidebar"', $stream);
        self::assertStringNotContainsString('href="/mail/trash"', $stream);

        self::assertSame(0, $this->trashRowsOn('/mail/inbox'), 'and stays gone');

        $this->toggle($trash);

        self::assertSame(1, $this->trashRowsOn('/mail/inbox'), 'switched back on');
    }

    /**
     * The eye, pressed: the form the settings page renders for this label,
     * submitted with its own CSRF token.
     */
    private function toggle(Label $label): void
    {
        $crawler = $this->client->request('GET', '/settings?section=labels');

        $form = $crawler
            ->filter(sprintf('form[action="/labels/%d/toggle-visibility"]', $label->id))
            ->form();

        $this->client->submit($form);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function trashRowsOn(string $page): int
    {
        return $this->client->request('GET', $page)
            ->filter('#sidebar a[href="/mail/trash"]')
            ->count();
    }
}
