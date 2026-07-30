<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every authenticated page answers 200 for an admin with no mail account.
 *
 * Cheap, wide coverage: a controller or template broken by a rename, a moved
 * class or a missing partial shows up here as a 500 instead of in the browser.
 * It asserts nothing about the contents — the e2e suite does that.
 *
 * Needs the seeded admin (`app:test:seed-user --admin`); skips itself without
 * one so a fresh checkout does not fail on missing fixtures.
 *
 * `/compose/new` and `/admin/user` are deliberately absent — both 500 today,
 * for reasons that have nothing to do with rendering. See "Findings worth
 * fixing" in todo.md; add them back here once they answer 200.
 */
final class PageRendersTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'inbox' => ['/mail/inbox'];
        yield 'starred' => ['/mail/starred'];
        yield 'sent' => ['/mail/sent'];
        yield 'drafts' => ['/mail/drafts'];
        yield 'trash' => ['/mail/trash'];
        yield 'archive' => ['/mail/archive'];
        yield 'search' => ['/mail/search?q=test'];
        yield 'settings' => ['/settings'];
        yield 'settings appearance' => ['/settings?section=appearance'];
        yield 'settings accounts' => ['/settings?section=accounts'];
        yield 'settings filters' => ['/settings?section=filters'];
        yield 'settings integrations' => ['/settings?section=integrations'];
        yield 'settings aliases' => ['/settings?section=aliases'];
        yield 'settings app passwords' => ['/settings?section=app-passwords'];
        yield 'settings notifications' => ['/settings?section=notifications'];
        yield 'settings general' => ['/settings?section=general'];
        yield 'filters list' => ['/settings/filters/list'];
        yield 'filters editor' => ['/settings/filters/new'];
        yield 'label editor' => ['/labels/new'];
        yield 'account editor' => ['/account/new'];
        yield 'admin dashboard' => ['/admin'];
        yield 'admin logs' => ['/admin/logs'];
        yield 'admin db' => ['/admin/db'];
        yield 'admin integrations' => ['/admin/integrations'];
    }

    #[DataProvider('pages')]
    public function testPageRenders(string $path): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('GET', $path);

        self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
    }
}
