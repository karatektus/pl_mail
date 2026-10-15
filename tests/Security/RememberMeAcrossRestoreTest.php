<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Domain\Enum\Backup\ConfigBackupDisposition;
use App\Entity\User\User;
use App\Service\Backup\ConfigBackupEnvironment;
use App\Service\Backup\ConfigBackupExporter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Http\RememberMe\PersistentRememberMeHandler;

/**
 * What a config backup must NOT let a browser do.
 *
 * A v2 backup carries users — the password hash included — and it used to carry
 * APP_SECRET as well. Those were both halves of what a signature-based
 * remember-me cookie was checked against, so restoring such a file onto a
 * second machine handed that machine's login to every browser still holding a
 * cookie from the first. Nobody chose that; it fell out of an inventory entry.
 *
 * Two stacks on one host is not a hypothetical, which is what made it worth
 * acting on: cookies are not scoped by port, so a browser on localhost sends
 * the same REMEMBERME to whatever answers on :80 and on :8002.
 *
 * The first fix was that the secret stops travelling — the target keeps the
 * one it generated at first boot. Remember-me has since moved to tokens kept
 * on the server (`rememberme_token`), and the cookie is no longer signed with
 * anything: it names a row. So the property now rests on that row not
 * travelling either, and both are asserted here — the secret because an old
 * habit of exporting it should not come back, the row because it is what a
 * cookie from the source machine would need to find on the target.
 */
final class RememberMeAcrossRestoreTest extends KernelTestCase
{
    /**
     * The enabler, gone: a backup written by this build cannot tell another
     * machine what to sign cookies with, because it does not know.
     */
    public function testTheBackupDoesNotCarryTheAppSecret(): void
    {
        self::bootKernel();

        $environment = static::getContainer()->get(ConfigBackupEnvironment::class);

        self::assertNotContains('APP_SECRET', $environment->variables(), 'APP_SECRET is back in the inventory');
        self::assertArrayNotHasKey(
            'APP_SECRET',
            $environment->export(),
            'this test stack sets APP_SECRET, and the export still picked it up',
        );

        // And an old backup that still carries one is refused rather than
        // written, so upgrading does not quietly leave the hole open.
        self::assertSame(
            ConfigBackupDisposition::KeptDeliberately,
            $environment->dispositionFor('APP_SECRET'),
            'an old backup could still overwrite this install\'s secret',
        );
    }

    /**
     * And what a cookie is checked against now: a row in `rememberme_token`.
     * The firewall holding the persistent handler is what makes that true, and
     * a backup taken while such a row exists must carry neither half of it —
     * a cookie from the source machine then names a series the target has
     * never heard of.
     */
    public function testARememberMeTokenDoesNotTravelInABackup(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            PersistentRememberMeHandler::class,
            static::getContainer()->get('security.authenticator.remember_me_handler.main'),
            'remember-me is no longer kept on the server, so what this test guards has moved again',
        );

        $series = 'a-series-only-the-source-machine-knows';
        $value  = 'the-token-value-that-goes-with-it';

        $connection = static::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $connection->insert('rememberme_token', [
                'series'   => $series,
                'value'    => $value,
                'lastUsed' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'class'    => User::class,
                'username' => 'remembered@plmail.test',
            ]);

            $backup = json_encode(static::getContainer()->get(ConfigBackupExporter::class)->document(), JSON_THROW_ON_ERROR);
        } finally {
            $connection->rollBack();
        }

        self::assertStringNotContainsString($series, $backup, 'the backup carries remember-me tokens to the next machine');
        self::assertStringNotContainsString($value, $backup, 'the backup carries remember-me tokens to the next machine');
    }
}
