<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Folders under their account, tags under LABELS (#221 step 3).
 *
 * On a plain IMAP account a label that is a folder there is listed under the
 * account and not under LABELS, which holds the labels put on mail; a tag is
 * under LABELS and not under the account. "Folders in Labels" lists folders
 * under LABELS again.
 */
final class SidebarFoldersUnderAccountsTest extends WebTestCase
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
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->user->expandedAccountId = $this->account->id;
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

    public function testAFolderIsUnderItsAccountAndATagUnderLabels(): void
    {
        $this->folder('Projects');
        $this->tag('Later');

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertSame(['Later'], $this->names($crawler, '#label-list'));
        self::assertContains('Projects', $this->names($crawler, '#account-folders-' . $this->account->id));
        self::assertNotContains('Later', $this->names($crawler, '#account-folders-' . $this->account->id));
    }

    public function testFoldersInLabelsListsThemThereAgain(): void
    {
        $this->folder('Projects');
        $this->tag('Later');

        $crawler = $this->client->request('GET', '/settings?section=labels');
        $button  = $crawler->filter('turbo-frame#labels-folders-in-labels button[data-folders-in-labels]');

        self::assertSame('false', $button->attr('aria-pressed'), 'off by default');

        $token   = (string) $crawler->filter('turbo-frame#labels-folders-in-labels input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', '/settings/labels/folders-in-labels', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('button[data-folders-in-labels]')->attr('aria-pressed'));
        self::assertTrue($this->em->find(User::class, $this->user->id)?->foldersInLabels);

        $crawler = $this->client->request('GET', '/mail/inbox');

        self::assertSame(['Later', 'Projects'], $this->names($crawler, '#label-list'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function tag(string $name): Label
    {
        $label = $this->seedLabel($name);

        $binding          = new LabelBinding();
        $binding->label   = $label;
        $binding->account = $this->account;
        $this->em->persist($binding);
        $this->em->flush();

        return $label;
    }

    private function folder(string $name): Label
    {
        $label = $this->tag($name);

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $this->em->persist($mailbox);

        $label->bindingFor($this->account)->mailbox = $mailbox;
        $this->em->flush();

        return $label;
    }

    /** @return list<string> the custom labels named in that part of the sidebar */
    private function names(Crawler $crawler, string $where): array
    {
        $names = $crawler->filter($where . ' .nav-item')->each(static fn (Crawler $row): string => trim($row->text()));

        return array_values(array_filter(
            array_map(static fn (string $text): string => trim((string) preg_replace('/\s+\d+$/', '', $text)), $names),
            static fn (string $name): bool => in_array($name, ['Projects', 'Later'], true),
        ));
    }
}
