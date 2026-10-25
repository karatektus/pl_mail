<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\System\AppVersion;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The build chip in the Administration header copies itself (#313).
 *
 * Read from the markup rather than through a browser: the e2e stack is a
 * checkout with no APP_VERSION and a git that refuses the bind-mounted
 * repository, so it never shows the chip at all. The copying is the
 * ui--clipboard controller's, which message-header.spec.ts drives for real;
 * what is pinned here is what this chip hands it.
 */
final class VersionChipTest extends WebTestCase
{
    private const string CHIP = 'button[data-controller="ui--clipboard"][data-action="click->ui--clipboard#copy"]';

    public function testTheChipCopiesTheLabelAndTheCommitWithOneSpaceBetween(): void
    {
        $chip = $this->header(new AppVersion('milestone-v0.4.0', '0805208c1f2e3d4b'))->filter(self::CHIP);

        self::assertCount(1, $chip);
        self::assertSame('milestone-v0.4.0 0805208', $chip->filter('[data-ui--clipboard-target="source"]')->text(normalizeWhitespace: false));
        self::assertSame('Copy the build milestone-v0.4.0 0805208', $chip->attr('aria-label'));
        self::assertCount(1, $chip->filter('i.fa-copy[data-ui--clipboard-target="icon"]'), 'the clipboard icon is what confirms the copy');
    }

    public function testABuildWithNoCommitCopiesItsLabelAlone(): void
    {
        $chip = $this->header(new AppVersion('v0.4.0'))->filter(self::CHIP);

        self::assertSame('v0.4.0', $chip->filter('[data-ui--clipboard-target="source"]')->text(normalizeWhitespace: false));
    }

    public function testACheckoutThatWasNeverBuiltShowsNoChip(): void
    {
        // A project directory git cannot describe: the label falls back to
        // "development", which the header leaves out.
        $header = $this->header(new AppVersion(null, null, '/nonexistent'));

        self::assertCount(0, $header->filter(self::CHIP));
    }

    private function header(AppVersion $version): Crawler
    {
        $client = $this->signedIn('e2e-admin@plmail.test');
        static::getContainer()->set(AppVersion::class, $version);

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();

        return $crawler->filter('.main-pane h1')->closest('div');
    }

    private function signedIn(string $email): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
