<?php

declare(strict_types=1);

namespace App\Tests\Controller\AddressBook;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The address book docked beside the mail, from the server's side.
 *
 * The claims:
 *   - whether it is open and how wide is remembered, behind a token, with the
 *     width clamped here and not only by the handle;
 *   - the mailbox draws it as it was left — open with the list already in it,
 *     or closed with an empty lazy frame — and the topbar button says so;
 *   - a contacts page asked for by the pane's frame is the frame alone, with
 *     the pane's own buttons, and the same page asked for normally is the
 *     mailbox with that page in the dock and the mail column put away, so a
 *     message opened from it can come in beside it without a visit.
 *
 * How it shares the row with the calendar is behaviour in the browser, and is
 * contacts-pane.spec.ts's.
 */
final class ContactsPaneTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/contacts/pane-state';

    public function testOpenAndWidthAreRemembered(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['open' => '1', 'width' => '560', '_token' => $this->token($client)]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->reread()->contactsPaneOpen);
        self::assertSame(560, $this->reread()->contactsPaneWidth);

        // Either field alone leaves the other as it was.
        $client->request('POST', self::PATH, ['open' => '0', '_token' => $this->token($client)]);

        self::assertFalse($this->reread()->contactsPaneOpen);
        self::assertSame(560, $this->reread()->contactsPaneWidth);
    }

    public function testWithoutATokenNothingIsWritten(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['open' => '1']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertFalse($this->reread()->contactsPaneOpen);
    }

    public function testTheWidthIsClampedHere(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['width' => '40000', '_token' => $this->token($client)]);
        self::assertSame(User::CONTACTS_PANE_MAX_WIDTH, $this->reread()->contactsPaneWidth);

        $client->request('POST', self::PATH, ['width' => '10', '_token' => $this->token($client)]);
        self::assertSame(User::CONTACTS_PANE_MIN_WIDTH, $this->reread()->contactsPaneWidth);
    }

    public function testAClosedPaneIsAnEmptyLazyFrame(): void
    {
        [$client] = $this->signedIn();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $dock = $crawler->filter('[data-side-pane="contacts"]');

        self::assertCount(1, $dock);
        self::assertSame('hidden shrink-0 min-w-0', $dock->attr('class'));
        self::assertSame('lazy', $dock->filter('turbo-frame#contacts-pane-frame')->attr('loading'));
        self::assertCount(0, $dock->filter('[data-contacts-page]'), 'nobody pays for a list they are not looking at');
        self::assertStringNotContainsString('lg:text-accent', (string) $crawler->filter('[data-contacts--pane-target="toggle"]')->attr('class'));
    }

    public function testAnOpenPaneIsDrawnWithTheListInItAtItsWidth(): void
    {
        [$client, $user] = $this->signedIn();

        $user->contactsPaneOpen = true;
        $user->setSetting(User::SETTING_CONTACTS_PANE_WIDTH, 512);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/mail/inbox');

        $dock = $crawler->filter('[data-side-pane="contacts"]');

        self::assertStringContainsString('lg:flex', (string) $dock->attr('class'));
        self::assertStringContainsString('--contacts-pane-width: 512px', (string) $dock->attr('style'));
        self::assertCount(1, $dock->filter('turbo-frame#contacts-pane-frame [data-contacts-page]'));
        self::assertCount(1, $dock->filter('[data-contacts-pane-actions]'));
        self::assertNull($dock->filter('turbo-frame#contacts-pane-frame')->attr('src'), 'rendered with the page, not fetched after it');
        self::assertStringContainsString('lg:text-accent', (string) $crawler->filter('[data-contacts--pane-target="toggle"]')->attr('class'));

        // The same kind of pane as the calendar: one container, one handle each.
        self::assertCount(2, $crawler->filter('[data-side-pane] > [role="separator"]'));
    }

    public function testThePaneGetsTheFrameAloneAndThePageGetsThePage(): void
    {
        [$client] = $this->signedIn();

        $crawler = $client->request('GET', '/contacts/new', server: ['HTTP_TURBO_FRAME' => 'contacts-pane-frame']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#contacts-pane-frame [data-contacts-page] form'));
        self::assertCount(1, $crawler->filter('[data-contacts-pane-actions]'));
        self::assertCount(0, $crawler->filter('html head title'), 'no page around the frame');
        self::assertCount(0, $crawler->filter('[data-controller~="ui--sidebar-drawer"]'));

        $crawler = $client->request('GET', '/contacts/new');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller~="ui--sidebar-drawer"]'));

        // The mailbox, in its full-contacts state: the page in the dock, which
        // is open whatever is remembered, and a mail pane beside it to open a
        // message in.
        $shell = $crawler->filter('[data-contacts-full]');

        self::assertCount(1, $shell);
        self::assertSame('true', $shell->attr('data-contacts--pane-open-value'));
        self::assertCount(1, $crawler->filter('[data-mail-column] [data-mail--mail-pane-target="reading"]'));

        $frame = $crawler->filter('[data-side-pane="contacts"] turbo-frame#contacts-pane-frame');

        self::assertCount(1, $frame->filter('[data-contacts-page] form'));
        self::assertSame('advance', $frame->attr('data-turbo-action'), 'a navigation inside it is the page\'s, while it has the row');
        self::assertNull($frame->attr('src'), 'the page itself, not fetched after it');
        // Drawn for when it docks beside the mail; app.css hides them until then.
        self::assertCount(1, $frame->filter('[data-contacts-pane-actions]'));
    }

    public function testTheMailboxIsNotTheFullContactsPage(): void
    {
        [$client] = $this->signedIn();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-contacts-full]'));
        self::assertNull($crawler->filter('turbo-frame#contacts-pane-frame')->attr('data-turbo-action'));
    }

    protected function tearDown(): void
    {
        if (null !== $user = $this->find()) {
            $user->contactsPaneOpen = false;
            $user->setSetting(User::SETTING_CONTACTS_PANE_WIDTH, User::CONTACTS_PANE_DEFAULT_WIDTH);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    /** A token minted for this session, read off the mailbox, the only place the real one comes from. */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/mail/inbox');

        return (string) $crawler->filter('[data-contacts--pane-token-value]')->attr('data-contacts--pane-token-value');
    }

    /** @return array{KernelBrowser, User} */
    private function signedIn(): array
    {
        $client = static::createClient();
        $user   = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $user->contactsPaneOpen = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($user);

        return [$client, $user];
    }

    private function reread(): User
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = $this->find();

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function find(): ?User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
