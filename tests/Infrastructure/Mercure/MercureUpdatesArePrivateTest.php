<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mercure;

use App\Infrastructure\Mercure\UserUpdate;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every Mercure update this application publishes is private.
 *
 * All eight notifiers used to publish public updates, which the hub hands to
 * any subscriber naming the topic — so the per-user topic grant in the
 * subscriber cookie guarded nothing, and one signed-in user could read another
 * user's `mail/user/<id>` stream. The fix is one factory; this pins both that
 * the factory marks updates private and that nothing goes around it.
 */
final class MercureUpdatesArePrivateTest extends TestCase
{
    public function testTheFactoryMarksUpdatesPrivate(): void
    {
        $update = UserUpdate::create(['mail/user/1'], '{}');

        self::assertTrue($update->isPrivate());
        self::assertSame(['mail/user/1'], $update->getTopics());
    }

    public function testNothingInSrcBuildsAnUpdateDirectly(): void
    {
        $src = dirname(__DIR__, 3).'/src';
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('php' !== $file->getExtension() || str_ends_with($file->getPathname(), '/Infrastructure/Mercure/UserUpdate.php')) {
                continue;
            }

            if (1 === preg_match('/new\s+(\\\\?Symfony\\\\Component\\\\Mercure\\\\)?Update\s*\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        self::assertSame([], $offenders, 'Build Mercure updates through UserUpdate::create() so they are private.');
    }
}
