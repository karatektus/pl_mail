<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\VanishedMessageReconciler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sweep clock only ever moves BACKWARD, and never invents a listing.
 *
 * markSweepDue exists for the idle supervisor: an EXPUNGE means "sweep this
 * folder now", which is expressed by backdating sweptAt past the cadence. The
 * two properties pinned here are what the reaper's coverage rule depends on:
 * a clock that jumped FORWARD would claim a listing that never happened, and
 * a NULL resurrected into a timestamp would turn "never listed" — which
 * withholds deletion account-wide — into "listed", which does not.
 */
final class MailboxSweepDueTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private MailboxRepository $mailboxes;

    protected function setUp(): void
    {
        self::bootKernel();
        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->mailboxes  = $container->get(MailboxRepository::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFreshSweepIsBackdatedPastTheCadence(): void
    {
        $id = $this->seedMailbox('now');

        $this->mailboxes->markSweepDue($id);

        $sweptAt = new \DateTimeImmutable((string) $this->sweptAt($id));
        $cadence = new \DateTimeImmutable('-' . VanishedMessageReconciler::SWEEP_INTERVAL_MINUTES . ' minutes +5 seconds');

        self::assertLessThan($cadence, $sweptAt, 'the clock must land past the cadence, so the next sync sweeps');
    }

    public function testAnAlreadyOlderClockIsNotAdvanced(): void
    {
        $id     = $this->seedMailbox('-3 days');
        $before = $this->sweptAt($id);

        $this->mailboxes->markSweepDue($id);

        self::assertSame($before, $this->sweptAt($id), 'advancing would claim a listing that never happened');
    }

    public function testNeverListedStaysNeverListed(): void
    {
        $id = $this->seedMailbox(null);

        $this->mailboxes->markSweepDue($id);

        self::assertNull($this->sweptAt($id), 'NULL withholds deletion account-wide, and must keep doing so');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function seedMailbox(?string $sweptAtModifier): int
    {
        $suffix = uniqid('', true);

        $user = new User();
        $user->email = 'sweep-' . $suffix . '@example.test';
        $user->nameFirst = 'Sweep';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Sweep Fixture';
        $account->username = 'sweep-' . $suffix . '@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();

        return (int) $this->connection->fetchOne(
            'INSERT INTO mailbox (account_id, name, full_path, is_sync_enabled, is_idle_enabled, swept_at, created_at, updated_at)
             VALUES (:account, :name, :name, true, false, :swept, NOW(), NOW()) RETURNING id',
            [
                'account' => (int) $account->id,
                'name'    => 'INBOX',
                'swept'   => null === $sweptAtModifier
                    ? null
                    : (new \DateTimeImmutable($sweptAtModifier))->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function sweptAt(int $mailboxId): ?string
    {
        $value = $this->connection->fetchOne('SELECT swept_at FROM mailbox WHERE id = ?', [$mailboxId]);

        return false === $value ? null : $value;
    }
}
