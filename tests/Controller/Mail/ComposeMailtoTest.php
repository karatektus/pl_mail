<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use App\Tests\Support\Mail\OpensComposeWindow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A `mailto:` link opening the compose window, from the server's side.
 *
 * Three ways in, one URL: the browser's protocol handler, the installed app's
 * manifest and a link clicked inside a message all ask for
 * /compose/new?mailto=…. What is pinned here is that the window that answers
 * carries what the link said — including Cc and Bcc rows that are SHOWN, since
 * a hidden row over addresses that will be sent to is a recipient the sender
 * never saw — that a page visit lands in the dock rather than as a bare
 * fragment, and that the manifest names the URL this controller reads.
 *
 * The parsing itself is MailtoLinkTest's.
 */
final class ComposeMailtoTest extends WebTestCase
{
    use OpensComposeWindow;

    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheWindowOpensWithWhatTheLinkCarries(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'sender@joder.dev');

        $link = 'mailto:ada@example.com?cc=bob@example.com&bcc=carol@example.com'
            . '&subject=Gr%C3%BC%C3%9Fe&body=Hello%0Athere';

        $crawler = $client->request('GET', '/compose/new?mailto=' . rawurlencode($link), server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ada@example.com', $crawler->filter('select[name$="[toAddresses][]"] option[selected]')->text());
        self::assertStringContainsString('bob@example.com', $crawler->filter('select[name$="[ccAddresses][]"] option[selected]')->text());
        self::assertStringContainsString('carol@example.com', $crawler->filter('select[name$="[bccAddresses][]"] option[selected]')->text());
        self::assertSame('Grüße', $crawler->filter('[data-compose--compose-target="subject"]')->attr('value'));
        self::assertStringContainsString(
            '<p>Hello</p><p>there</p>',
            (string) $crawler->filter('input[name$="[bodyHtml]"]')->attr('value'),
        );

        foreach (['ccField', 'bccField'] as $row) {
            self::assertStringNotContainsString(
                'hidden',
                (string) $crawler->filter(sprintf('[data-compose--compose-target="%s"]', $row))->attr('class'),
                sprintf('the %s row is shown when it has addresses on it', $row),
            );
        }
    }

    /**
     * Opening the window saves nothing: the first autosave does. A link opened
     * by mistake must not leave a draft behind.
     */
    public function testOpeningALinkLeavesNoDraft(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'sender@joder.dev');

        $before = $this->messageCount();

        $client->request('GET', '/compose/new?mailto=' . rawurlencode('mailto:ada@example.com?subject=Hi'), server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->messageCount());
    }

    public function testWithoutCcTheRowsStayFolded(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'sender@joder.dev');

        $crawler = $client->request('GET', '/compose/new?mailto=' . rawurlencode('mailto:ada@example.com'), server: self::DOCK_FRAME);

        self::assertStringContainsString(
            'hidden',
            (string) $crawler->filter('[data-compose--compose-target="ccField"]')->attr('class'),
        );
    }

    public function testAPageVisitIsSentToTheMailboxDockWithTheLinkIntact(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'sender@joder.dev');

        $link = 'mailto:ada@example.com?subject=See%20https%3A%2F%2Fexample.org';

        $client->request('GET', '/compose/new?mailto=' . rawurlencode($link));

        self::assertResponseRedirects();

        $location = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $compose = (string) ($query['compose'] ?? '');

        // The dock only loads a `compose` that starts with /compose/ and holds
        // no `//` (templates/_layout/app.html.twig). A link carrying a URL must
        // still pass that, which it does because the link stays encoded.
        self::assertStringStartsWith('/compose/new?', $compose);
        self::assertStringNotContainsString('//', $compose);

        parse_str((string) parse_url($compose, PHP_URL_QUERY), $inner);
        self::assertSame($link, $inner['mailto'] ?? null);
    }

    public function testTheManifestHandsMailtoLinksToThisRoute(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/manifest.webmanifest'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            [['protocol' => 'mailto', 'url' => '/compose/new?mailto=%s']],
            $manifest['protocol_handlers'] ?? null,
        );
    }

    // ---------------------------------------------------------------- helpers

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function messageCount(): int
    {
        return $this->em->getRepository(Message::class)->count([]);
    }
}
