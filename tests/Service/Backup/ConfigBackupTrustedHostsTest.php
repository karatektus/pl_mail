<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Integration\TrustedHost;
use App\Repository\Integration\TrustedHost\TrustedHostRepository;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The servers on a private network travel with a config backup (#564).
 *
 * They were two environment variables, which the backup carried; as rows they
 * would have been the one admin setting a restore silently dropped, and a
 * restored install would refuse every LAN calendar it had been syncing. Keyed
 * by host like the providers by name: a restore adds and corrects what the
 * file names and leaves every other allowed host alone — "restore my
 * backup" must not be a way to lose a server allowed since.
 */
final class ConfigBackupTrustedHostsTest extends KernelTestCase
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

    public function testTheExportCarriesEveryHostWithItsHttpTick(): void
    {
        $this->em->persist(new TrustedHost('nas.lan', allowHttp: true));
        $this->em->persist(new TrustedHost('10.0.0.5'));
        $this->em->flush();

        $exported = self::getContainer()->get(ConfigBackupDatabase::class)->export()[ConfigBackupDatabase::TRUSTED_HOSTS];

        self::assertSame([
            '10.0.0.5' => ['allowHttp' => false],
            'nas.lan'  => ['allowHttp' => true],
        ], $exported);
    }

    public function testARestoreAddsAndCorrectsTheFilesHostsAndLeavesOthersAlone(): void
    {
        $this->em->persist(new TrustedHost('nas.lan'));
        $this->em->persist(new TrustedHost('allowed-since.lan'));
        $this->em->flush();

        self::getContainer()->get(ConfigBackupImporter::class)->apply([
            'database' => [
                ConfigBackupDatabase::TRUSTED_HOSTS => [
                    'nas.lan'        => ['allowHttp' => true],
                    'dav.home.lan'   => ['allowHttp' => false],
                    // Written by hand or by a broken export: not a host, so
                    // not a row the admin page could ever match.
                    '*.lan'          => ['allowHttp' => true],
                ],
            ],
        ]);
        $this->em->clear();

        $rows = [];

        foreach (self::getContainer()->get(TrustedHostRepository::class)->findAllOrdered() as $row) {
            $rows[$row->host] = $row->allowHttp;
        }

        self::assertSame([
            'allowed-since.lan' => false,
            'dav.home.lan'      => false,
            'nas.lan'           => true,
        ], $rows);
    }
}
