<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Where the running clock is drawn: the default, a round trip, and the guard.
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
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testTheTopbarIsTheDefaultAndTheChoiceMovesIt(): void
    {
        $client = $this->signedIn();

        $crawler = $client->request('GET', '/settings?section=general');

        self::assertCount(1, $crawler->filter('header [data-clock-placement="topbar"]'));
        self::assertCount(0, $crawler->filter('[data-clock-placement="sidebar"]'));

        $client->request('POST', self::PATH, ['placement' => 'sidebar', '_token' => $this->token($client)]);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', '/settings?section=general');

        self::assertCount(0, $crawler->filter('[data-clock-placement="topbar"]'));
        self::assertGreaterThan(0, $crawler->filter('aside [data-clock-placement="sidebar"]')->count());

        $client->request('POST', self::PATH, ['placement' => 'off', '_token' => $this->token($client)]);
        $crawler = $client->request('GET', '/settings?section=general');

        self::assertCount(0, $crawler->filter('[data-clock-placement]'));
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
        return (string) $client->request('GET', '/settings?section=general')
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
