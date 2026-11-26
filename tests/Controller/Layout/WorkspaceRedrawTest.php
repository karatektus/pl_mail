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
use Symfony\Component\DomCrawler\Crawler;

/**
 * The one stream a workspace switch draws from (#783): each frame the page
 * names, drawn by its own route for the workspace in the URL, before the
 * switch is saved.
 *
 * The guard matters as much as the drawing. A sub-request skips the
 * firewall's access rules, so the route follows only the frames a workspace
 * narrows, and only paths on this host; anything else is left out of the
 * stream rather than drawn.
 */
final class WorkspaceRedrawTest extends WebTestCase
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

        $this->user->setSetting(User::SETTING_WORKSPACES, ['workspaces' => [
            ['id' => 'mail', 'name' => 'Mail', 'layout' => ['t' => 'pane', 'kind' => 'list']],
            ['id' => 'bare', 'name' => 'Bare', 'layout' => ['t' => 'pane', 'kind' => 'list'], 'sidebar' => null],
        ], 'active' => 0]);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEachNamedFrameComesBackAsOneStreamAction(): void
    {
        $streams = $this->redraw('mail', ['workspace-sidebar /layout/sidebar?drawer=0', 'inbox-list-frame /mail/inbox']);

        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=utf-8');
        self::assertSame(['update:workspace-sidebar', 'replace:inbox-list-frame'], $streams);
    }

    public function testTheFramesAreDrawnForTheWorkspaceInTheUrlAndNothingIsSaved(): void
    {
        $this->redraw('bare', ['workspace-sidebar /layout/sidebar?drawer=0']);
        $bare = $this->template('workspace-sidebar');

        $this->redraw('mail', ['workspace-sidebar /layout/sidebar?drawer=0']);
        $mail = $this->template('workspace-sidebar');

        self::assertCount(0, $bare->filter('#sidebar'), 'a workspace without a sidebar draws none');
        self::assertCount(1, $mail->filter('#sidebar'));

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(0, $this->reread()->getSetting(User::SETTING_WORKSPACES)['active'], 'the switch is saved by its own request');
    }

    public function testOnlyTheRoutesAWorkspaceNarrowsAreFollowed(): void
    {
        $streams = $this->redraw('mail', [
            'settings /settings/region',
            'evil //elsewhere.example/mail/inbox',
            'nonsense no-slash',
            'missing /mail/nowhere-at-all',
            'inbox-list-frame /mail/inbox',
        ]);

        self::assertSame(['replace:inbox-list-frame'], $streams);
    }

    public function testAWorkspaceThatIsNotTheUsersIsNotFound(): void
    {
        $this->client->request('GET', '/layout/workspace/somebody-elses/redraw', ['f' => ['inbox-list-frame /mail/inbox']]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $frames
     *
     * @return list<string> "action:target" of each stream element
     */
    private function redraw(string $workspace, array $frames): array
    {
        $this->client->request('GET', '/layout/workspace/' . $workspace . '/redraw', ['f' => $frames]);

        self::assertResponseIsSuccessful();

        return array_map(
            static fn (\DOMElement $element): string => $element->getAttribute('action') . ':' . $element->getAttribute('target'),
            iterator_to_array($this->streams()),
        );
    }

    /** @return \Traversable<\DOMElement> */
    private function streams(): \Traversable
    {
        return (new Crawler('<body>' . (string) $this->client->getResponse()->getContent() . '</body>'))->filter('turbo-stream');
    }

    private function template(string $target): Crawler
    {
        $stream = (new Crawler('<body>' . (string) $this->client->getResponse()->getContent() . '</body>'))->filter('turbo-stream[target="' . $target . '"] template');

        self::assertCount(1, $stream);

        return new Crawler('<body>' . $stream->html() . '</body>');
    }

    private function reread(): User
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
