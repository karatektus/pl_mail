<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Oidc\OidcConfig;
use App\Repository\Oidc\OidcConfigRepository;
use App\Service\Backup\ConfigBackupDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A restore never brings back "single sign-on only".
 *
 * The setting is safe only beside the account links that let people through
 * the provider, and those are not in the backup. Restoring it would leave a
 * freshly restored install with no password form and nobody linked — the
 * lockout the admin form's own checks exist to prevent. So the restore turns
 * it off even over a row that had it on, which is the case asserted here: a
 * restore into an empty table would pass on the column default alone.
 */
final class ConfigBackupOidcTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testARestoreLeavesSingleSignOnOnlyOffOverARowThatHadItOn(): void
    {
        $row                        = new OidcConfig();
        $row->issuer                = 'https://auth.example.com/';
        $row->clientId              = 'plmail';
        $row->clientSecret          = 'the-secret';
        $row->passwordLoginDisabled = true;

        $this->em->persist($row);
        $this->em->flush();

        self::getContainer()->get(ConfigBackupDatabase::class)->restoreOidcConfig([
            'issuer'       => 'https://auth.example.com/',
            'clientId'     => 'plmail',
            'clientSecret' => 'the-secret',
            // A document that somehow carries the key is not believed either.
            'passwordLoginDisabled' => true,
        ]);
        $this->em->flush();
        $this->em->clear();

        $current = self::getContainer()->get(OidcConfigRepository::class)->current();

        self::assertNotNull($current);
        self::assertTrue($current->isComplete());
        self::assertFalse($current->passwordLoginDisabled);
    }
}
