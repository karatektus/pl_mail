<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Tests\Support\Mail\OpensComposeWindow;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The forward-quote fold, round-tripped: who may set it, what is stored, and
 * what the compose window is told.
 *
 * The storage convention is the assertion worth having: "folded" is the
 * default and is stored as NOTHING — the absent key — so a user who never
 * visited the setting and one who set it back to folded are the same user.
 * Only "open" writes.
 */
final class ComposeBehaviorTest extends WebTestCase
{
    use OpensComposeWindow;

    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/settings/compose-behavior';

    private ?Account $account = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        if (null !== $user = $this->find()) {
            $user->setSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED, null);
        }

        // The window test's throwaway account — the PHPUnit seed admin has no
        // mailbox of their own, and /compose/new answers "connect an account"
        // instead of a window without one.
        if (null !== $this->account && null !== $this->account->id) {
            $em->remove($em->find(Account::class, $this->account->id) ?? $this->account);
        }

        $em->flush();

        parent::tearDown();
    }

    public function testOpenIsStoredAndFoldedIsUnstored(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, [
            'forwardQuoteCollapsed' => '0',
            '_token' => $this->token($client),
        ]);

        self::assertResponseRedirects();
        self::assertFalse(
            $this->reread()->getSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED, true),
        );

        $client->request('POST', self::PATH, [
            'forwardQuoteCollapsed' => '1',
            '_token' => $this->token($client),
        ]);

        // Back to the default means back to the absent key, not a stored true.
        self::assertNull(
            $this->reread()->getSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED),
        );
    }

    public function testATokenlessPostIsRefusedAndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::PATH, ['forwardQuoteCollapsed' => '0']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->reread()->getSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED));
    }

    public function testTheComposeWindowIsToldTheChoice(): void
    {
        [$client, $user] = $this->signedIn();

        $user->setSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED, false);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->name           = 'Compose fixture';
        $this->account->email          = uniqid('compose-', true) . '@example.test';
        $this->account->username       = uniqid('compose-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $em->persist($this->account);
        $em->flush();

        $crawler = $client->request('GET', '/compose/new', server: self::DOCK_FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'false',
            $crawler->filter('[data-compose--compose-collapse-forward-quote-value]')
                ->first()->attr('data-compose--compose-collapse-forward-quote-value'),
        );
    }

    /** @return array{KernelBrowser, User} */
    private function signedIn(): array
    {
        $client = static::createClient();

        $user = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return [$client, $user];
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=general');

        return (string) $crawler
            ->filter('form[action$="/settings/compose-behavior"] input[name="_token"]')
            ->first()->attr('value');
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
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
