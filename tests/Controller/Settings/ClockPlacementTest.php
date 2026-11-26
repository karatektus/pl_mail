<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Where the running clock is drawn: the default, a round trip, and the guard.
 *
 * The sidebar stopped being one of the places to choose with #783: the Mail
 * sidebar has a clock switch of its own (User::$mailSidebarClock), and a
 * sidebar of one's own a Clock row. Somebody who had chosen the sidebar keeps
 * the clock there, read through that switch.
 *
 * Asserted on `data-clock-placement` rather than on classes, so the claim is
 * "the clock is in the sidebar" and not how the sidebar happens to lay it out.
 */
final class ClockPlacementTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string PATH = '/settings/clock/placement';

    protected function tearDown(): void
    {
        if (null !== $user = $this->find()) {
            $user->setSetting(User::SETTING_CLOCK_PLACEMENT, null);
            $user->setSetting(User::SETTING_MAIL_SIDEBAR_CLOCK, null);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testTheTopbarIsTheDefaultAndTheChoiceMovesIt(): void
    {
        $client = $this->signedIn();

        $crawler = $client->request('GET', '/settings/region');

        self::assertCount(1, $crawler->filter('header [data-clock-placement="topbar"]'));
        self::assertCount(0, $crawler->filter('[data-clock-placement="sidebar"]'));

        $client->request('POST', self::PATH, ['placement' => 'off', '_token' => $this->token($client)]);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', '/settings/region');

        self::assertCount(0, $crawler->filter('[data-clock-placement]'));
    }

    public function testTheSidebarIsNoLongerAPlacementButTheMailSidebarsSwitch(): void
    {
        $client = $this->signedIn();

        $client->request('POST', self::PATH, ['placement' => 'sidebar', '_token' => $this->token($client)]);
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $user = $this->find();
        self::assertNotNull($user);
        $user->mailSidebarClock = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertGreaterThan(0, $crawler->filter('aside [data-clock-placement="sidebar"]')->count());
        self::assertCount(1, $crawler->filter('header [data-clock-placement="topbar"]'), 'the top bar keeps its own');
    }

    public function testWhoeverHadTheClockInTheSidebarKeepsIt(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_CLOCK_PLACEMENT, 'sidebar');

        self::assertTrue($user->mailSidebarClock, 'read from the old placement');
        self::assertSame('off', $user->clockPlacement->value, 'and no longer a place of its own');

        $user->mailSidebarClock = false;
        self::assertFalse($user->mailSidebarClock, 'once written, the switch is its own');
    }

    public function testAnUnknownPlacementIsRefusedAndWritesNothing(): void
    {
        $client = $this->signedIn();

        $client->request('POST', self::PATH, ['placement' => 'wall', '_token' => $this->token($client)]);

        self::assertSame(404, $client->getResponse()->getStatusCode());

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull($this->find()?->getSetting(User::SETTING_CLOCK_PLACEMENT));
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();
        $user   = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }

    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/settings/region')
            ->filter('form[action$="/settings/clock/placement"] input[name="_token"]')
            ->first()->attr('value');
    }

    private function find(): ?User
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
