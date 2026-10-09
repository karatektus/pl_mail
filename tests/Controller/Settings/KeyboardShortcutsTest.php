<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The keyboard shortcuts are on until somebody switches them off, and off is
 * the absence of the script (#36).
 *
 * What the keys do is a browser's to prove — tests/e2e/keyboard-shortcuts.spec.ts.
 * What is the server's is the part a key cannot be trusted with: whether the
 * controller is on the page at all. There is no flag for it to read and forget
 * to read; a person who switched the shortcuts off is served a <body> that has
 * nothing listening for them.
 */
final class KeyboardShortcutsTest extends WebTestCase
{
    private const string EMAIL = 'shortcuts-pref@plmail.test';

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
        $user->nameFirst = 'Short';
        $user->nameLast  = 'Cut';
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

    public function testTheyAreOnForSomebodyWhoHasNeverBeenAsked(): void
    {
        self::assertTrue($this->reread()->keyboardShortcuts);
        self::assertNull($this->reread()->getSetting(User::SETTING_KEYBOARD_SHORTCUTS), 'on is the absent key, not a stored true');

        $body = $this->settings()->filter('body');

        self::assertStringContainsString('ui--shortcuts', (string) $body->attr('data-controller'));

        $go = json_decode((string) $body->attr('data-ui--shortcuts-go-value'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('/mail/inbox', $go['i']);
        self::assertSame('/mail/starred', $go['s']);
        self::assertCount(1, $this->settings()->filter('[data-shortcut="help"]'));
    }

    public function testSwitchedOffNothingOnThePageListensForKeys(): void
    {
        $this->post(['enabled' => '0']);

        self::assertResponseRedirects();
        self::assertFalse($this->reread()->keyboardShortcuts);

        $page = $this->settings();

        self::assertStringNotContainsString('ui--shortcuts', (string) $page->filter('body')->attr('data-controller'));
        self::assertNull($page->filter('body')->attr('data-ui--shortcuts-go-value'));
        self::assertCount(0, $page->filter('[data-shortcut="help"]'));

        // And back on is back to the absent key.
        $this->post(['enabled' => '1']);

        self::assertTrue($this->reread()->keyboardShortcuts);
        self::assertNull($this->reread()->getSetting(User::SETTING_KEYBOARD_SHORTCUTS));
    }

    /** A post that does not mention the switch does not move it. */
    public function testAPostWithoutTheFieldChangesNothing(): void
    {
        $this->post(['enabled' => '0']);
        $this->post([]);

        self::assertFalse($this->reread()->keyboardShortcuts);
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/shortcuts', ['enabled' => '0']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reread()->keyboardShortcuts);
    }

    /**
     * The list is served to somebody who has them off as well — they are the
     * person deciding whether to switch them on — and says so.
     */
    public function testTheListIsServedEitherWay(): void
    {
        $list = $this->client->request('GET', '/shortcuts');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $list->filter('turbo-frame#modal [data-shortcuts-help]'));
        self::assertGreaterThan(25, $list->filter('[data-shortcuts-help] kbd')->count());
        self::assertStringNotContainsString('switched off', $list->text());
        // A missing translation renders as its own key.
        self::assertStringNotContainsString('shortcuts.keys.', $list->text());

        $this->post(['enabled' => '0']);

        self::assertStringContainsString('switched off', $this->client->request('GET', '/shortcuts')->text());
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $token = (string) $this->settings()
            ->filter('form[action="/settings/shortcuts"] input[name="_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/settings/shortcuts', ['_token' => $token, ...$fields]);
    }

    private function settings(): Crawler
    {
        return $this->client->request('GET', '/settings?section=general');
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
