<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261114120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That the send switch starts out as what each account could actually do
 * (#628): on where mail was, off where mail was, and off on a password account
 * that had no SMTP server to send through and no JMAP server to submit to.
 *
 * The last is the one that matters. Those accounts were offered in From and
 * every send from them failed; switching them on would keep that, switching
 * every password account off would take sending from people who had it.
 *
 * Only the backfill is run — the column already exists in the test schema —
 * and against rows, so a statement that applies and fills the wrong accounts
 * fails here rather than on somebody's install.
 */
final class SendSwitchMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSendingStartsOutWhereTheAccountCouldSend(): void
    {
        $user = MailFixtures::user($this->em, 'sendmig');

        $sends     = MailFixtures::account($this->em, $user);
        $mailOff   = MailFixtures::account($this->em, $user, mailEnabled: false);
        $noSmtp    = MailFixtures::account($this->em, $user);
        $blankSmtp = MailFixtures::account($this->em, $user);
        $jmap      = MailFixtures::account($this->em, $user);
        $oauth     = MailFixtures::account($this->em, $user);

        $noSmtp->smtpHost       = null;
        $blankSmtp->smtpHost    = '  ';
        $jmap->smtpHost         = null;
        $jmap->incomingProtocol = IncomingProtocol::Jmap;
        $oauth->smtpHost        = null;
        $oauth->authType        = AuthType::OAuth2->value;
        $oauth->oauthProvider   = 'google';

        // Each the opposite of what the backfill should write, so a statement
        // that leaves a row alone is caught as well as one that gets it wrong.
        $sends->sendEnabled     = false;
        $mailOff->sendEnabled   = true;
        $noSmtp->sendEnabled    = true;
        $blankSmtp->sendEnabled = true;
        $jmap->sendEnabled      = false;
        $oauth->sendEnabled     = false;

        $this->em->flush();

        $this->backfill();

        self::assertTrue($this->sendEnabled($sends));
        self::assertFalse($this->sendEnabled($mailOff), 'mail off sent nothing before, and still does not');
        self::assertFalse($this->sendEnabled($noSmtp), 'nothing to send through');
        self::assertFalse($this->sendEnabled($blankSmtp), 'a blank host is no host');
        self::assertTrue($this->sendEnabled($jmap), 'a JMAP server takes submissions');
        self::assertTrue($this->sendEnabled($oauth), 'Gmail and Microsoft send through their API');
    }

    private function backfill(): void
    {
        // Read by Doctrine's loader, not Composer's, so required here.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261114120000.php';

        $migration = new Version20261114120000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            if (true === str_starts_with(trim($query->getStatement()), 'UPDATE')) {
                $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    private function sendEnabled(Account $account): bool
    {
        return true === (bool) $this->connection->fetchOne('SELECT send_enabled FROM account WHERE id = ?', [$account->id]);
    }
}
