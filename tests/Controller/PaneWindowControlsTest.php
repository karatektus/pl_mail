<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The calendar pane and the address book end their headers the same way:
 * ↗ full width, ↙ back beside the mail, × close, each from
 * _pane_window_controls.html.twig and each wired to its own pane's controller
 * (#678).
 *
 * The calendar had none of the three, so it could only be closed or widened
 * from the topbar, while the address book beside it had ↗ and × . Asserted on
 * the first paint, with both panes rendered open into the mailbox, because
 * that is where the two are compared: side by side. Which of ↗ and ↙ shows is
 * app.css's, keyed on the shell's position, and the clicks are in
 * tests/e2e/calendar-pane.spec.ts and contacts-pane.spec.ts.
 */
final class PaneWindowControlsTest extends WebTestCase
{
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
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testBothDockedPanesHaveTheSameWindowControls(): void
    {
        $crawler = $this->openInbox();

        foreach (['calendar' => 'ui--split', 'contacts' => 'contacts--pane'] as $pane => $controller) {
            $controls = $crawler->filter(sprintf('[data-side-pane="%s"] [data-pane-window-controls]', $pane));

            self::assertCount(1, $controls, $pane);

            foreach (['expand', 'restore', 'close'] as $action) {
                $button = $controls->filter(sprintf('[data-pane-%s]', $action));

                self::assertCount(1, $button, $pane . ' ' . $action);
                self::assertSame($controller . '#' . $action, $button->attr('data-action'), $pane . ' ' . $action);
                self::assertSame('icon-btn', $button->attr('class'), $pane . ' ' . $action);
                self::assertNotEmpty($button->attr('aria-label'), $pane . ' ' . $action);
            }
        }
    }

    public function testThePanesHeaderButtonsAreTheSameButton(): void
    {
        $crawler = $this->openInbox();

        // The + of each pane: the one accented action either header has.
        self::assertCount(1, $crawler->filter('[data-side-pane="calendar"] [data-calendar-new-event].icon-btn.icon-btn-primary'));
        self::assertCount(1, $crawler->filter('[data-side-pane="contacts"] a[href="/contacts/new"].icon-btn.icon-btn-primary'));

        // The address book's other header actions are quiet icons, no border.
        $import = $crawler->filter('[data-side-pane="contacts"] a[href="/contacts/import"]');

        self::assertSame('icon-btn', $import->attr('class'));
    }

    public function testTheCalendarPageHasNoWindowControls(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/calendar');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-pane-window-controls]'), 'a page has nothing to dock back into');
    }

    private function openInbox(): Crawler
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function signIn(): void
    {
        $user            = new User();
        $user->email     = 'panes-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Pane';
        $user->nameLast  = 'Controls';
        $user->roles     = [User::ROLE_USER];
        $user->password  = 'x';

        $user->calendarPaneMode = CalendarPaneMode::Split;
        $user->contactsPaneOpen = true;

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }
}
