<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\LabelRole;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Two panels of one app, on screen at the same time, saying the same things.
 *
 * Two reports land here and they are the same shape: something the sidebar
 * renders and something else renders were built by different code, so they
 * drifted.
 *
 *   - Settings → Labels listed "Inbox, Sent, Drafts, Spam, Trash, Archive"
 *     while the German sidebar beside it read "Posteingang, Gesendet,
 *     Entwürfe, Spam, Papierkorb, Archiv". A system label's `name` column is
 *     its English creation name; the sidebar never printed it (its rows are
 *     hardcoded `sidebar.nav.*` translations) and the settings list did.
 *   - The desktop rail and the mobile drawer both render `_sidebar.html.twig`
 *     into the same document, so every account's folder frame and expand
 *     toggle existed twice under one id. Duplicate ids are invalid HTML and
 *     Turbo resolves frame targets BY id.
 *
 * Against a fixture user of its own rather than the shared admin seed: both
 * questions need accounts and system labels to exist, and the admin seed has
 * neither reliably — which is how a test asserting "no duplicate account frame
 * ids" can pass on a page that renders no accounts at all.
 */
final class SidebarAndSettingsAgreeTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private ?Connection $connection = null;

    /** The six the report named, in the order the settings list shows them. */
    private const array SYSTEM_LABELS = [
        ['Inbox', LabelRole::Inbox],
        ['Sent', LabelRole::Sent],
        ['Drafts', LabelRole::Drafts],
        ['Spam', LabelRole::Spam],
        ['Trash', LabelRole::Trash],
        ['Archive', LabelRole::Archive],
    ];

    private function fixtureClient(int $accounts = 2): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();

        for ($n = 0; $n < $accounts; ++$n) {
            $this->account = $this->seedAccount();
        }

        foreach (self::SYSTEM_LABELS as [$name, $role]) {
            $label = $this->seedLabel($name, $role);

            if (LabelRole::Inbox === $role) {
                $this->inbox = $label;
            }
        }

        $client->loginUser($this->user);

        return $client;
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        parent::tearDown();
    }

    // ── the system folder names ───────────────────────────────────────────

    /**
     * The reported contradiction, as an assertion: with the UI in German, the
     * settings list must not be printing the English creation names.
     */
    public function testSettingsLabelsDoNotShowTheEnglishCreationNames(): void
    {
        $client = $this->german();

        $client->request('GET', '/settings?section=labels');

        self::assertResponseIsSuccessful();

        $shown = $this->texts($client->getCrawler(), '#settings-label-list li span.truncate');

        self::assertNotEmpty($shown, 'the labels panel rendered no rows');

        // Spam is deliberately absent: its German name IS "Spam", so it is the
        // one row that cannot tell a translated list from an untranslated one.
        foreach (['Inbox', 'Sent', 'Drafts', 'Trash', 'Archive'] as $creationName) {
            self::assertNotContains(
                $creationName,
                $shown,
                sprintf('Settings → Labels still prints the creation name "%s"', $creationName),
            );
        }
    }

    /**
     * And the stronger half: whatever the sidebar calls a folder, this list
     * calls it too. Read off the sidebar's own rendering rather than compared
     * against fixed German strings, so it keeps meaning something when the
     * wording changes.
     */
    public function testSettingsLabelsUseTheSameNamesAsTheSidebar(): void
    {
        $client = $this->german();

        $client->request('GET', '/settings?section=labels');

        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();

        $settingsRows = $this->texts($crawler, '#settings-label-list li span.truncate');

        self::assertNotEmpty($settingsRows, 'the labels panel rendered no rows');

        // Read off what the sidebar ACTUALLY rendered, found by the route each
        // row links to rather than by its text — the text is the thing under
        // test. Inbox, Sent, Drafts and Trash are always in both lists, so they
        // are the four the two surfaces can be held to naming identically.
        $router = static::getContainer()->get('router');

        foreach (['app_mail_inbox', 'app_mail_sent', 'app_mail_drafts', 'app_mail_trash'] as $route) {
            $row = $crawler->filter(sprintf('#sidebar nav a[href="%s"] .rail-hide', $router->generate($route)));

            self::assertGreaterThan(0, $row->count(), sprintf('the sidebar has no row for %s', $route));

            $name = trim($row->first()->text());

            self::assertContains(
                $name,
                $settingsRows,
                sprintf('the sidebar calls it "%s" and Settings → Labels does not', $name),
            );
        }
    }

    // ── the duplicated ids ────────────────────────────────────────────────

    /**
     * Every id in the mailbox shell is unique.
     *
     * Reported against `account-folders-20` and `-22` and the expand toggles,
     * each of which appeared twice: once in the mobile drawer and once in the
     * desktop rail. Nothing was seen misbehaving, which is rather the point —
     * Turbo finds a frame by id and nothing guarantees it finds the one the
     * user is looking at.
     *
     * Asserted over the whole document rather than that one pair, because the
     * cause is a partial rendered twice and the next id added to it would have
     * exactly the same problem.
     */
    public function testTheMailboxShellHasNoDuplicateIds(): void
    {
        $client = $this->fixtureClient();

        $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $ids = $client->getCrawler()->filter('[id]')->each(
            static fn (Crawler $node): string => (string) $node->attr('id'),
        );

        self::assertNotEmpty($ids);

        $duplicates = array_keys(array_filter(
            array_count_values($ids),
            static fn (int $count): bool => $count > 1,
        ));

        self::assertSame([], $duplicates, 'rendered more than once: ' . implode(', ', $duplicates));
    }

    /** Both sidebars really are rendering their own copy, or the above is vacuous. */
    public function testBothSidebarsRenderAFolderFrameForEveryAccount(): void
    {
        $client = $this->fixtureClient(accounts: 2);

        $client->request('GET', '/mail/inbox');

        $frames = $client->getCrawler()->filter('turbo-frame[id^="account-folders-"]')->each(
            static fn (Crawler $node): string => (string) $node->attr('id'),
        );

        self::assertCount(4, $frames, 'two accounts in two sidebars is four distinct frames');
        self::assertSame($frames, array_values(array_unique($frames)));
        self::assertCount(2, array_filter($frames, static fn (string $id): bool => str_ends_with($id, '-drawer')));
    }

    /**
     * A unique id is no use if aria-controls now points at the other sidebar's
     * frame, or at nothing.
     */
    public function testEveryAccountToggleControlsAFrameThatExists(): void
    {
        $client = $this->fixtureClient();

        $client->request('GET', '/mail/inbox');

        $crawler = $client->getCrawler();

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));

        $controls = $crawler->filter('[aria-controls^="account-folders-"]')->each(
            static fn (Crawler $node): string => (string) $node->attr('aria-controls'),
        );

        self::assertNotEmpty($controls, 'no account toggles were rendered');

        foreach ($controls as $target) {
            self::assertContains($target, $ids, sprintf('the toggle points at "%s", which is not on the page', $target));
        }

        // Each toggle controls its own frame and no two share one.
        self::assertSame($controls, array_values(array_unique($controls)));
    }

    /**
     * The locale is the user's, applied by UserLocaleSubscriber on every
     * request — calling setLocale() on the translator by hand does not survive
     * the next one, which is how a version of this test came to compare English
     * against English and pass while the bug was still there.
     */
    private function german(): KernelBrowser
    {
        $client = $this->fixtureClient();

        $this->user->locale = 'de';
        $this->em->flush();

        return $client;
    }

    /**
     * @return list<string>
     */
    private function texts(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
