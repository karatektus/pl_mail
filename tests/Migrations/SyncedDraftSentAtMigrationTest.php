<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261015130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That a draft synced before the upgrade opens in the editor afterwards, and
 * that a draft already sent from another client does not become sendable.
 *
 * Every row is seeded the way the old builders stored it: flagged `\Draft`, with
 * its receivedAt as its sentAt. What tells the two kinds apart is where the
 * provider files the message, so each "cleared" case has a "kept" case beside it
 * that differs only in that.
 *
 * Run against rows, not against the SQL text: the failure to guard against is a
 * statement that is valid, applies, and touches the wrong drafts.
 */
final class SyncedDraftSentAtMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private Label $drafts;
    private Label $sent;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->drafts  = $this->seedLabel('Drafts', LabelRole::Drafts);
        $this->sent    = $this->seedLabel('Sent', LabelRole::Sent);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAGmailDraftStillLabelledDraftLosesItsSentAt(): void
    {
        $draft = $this->seed(gmailLabels: ['DRAFT']);

        $this->migrate();

        self::assertNull($this->sentAtOf($draft), 'presence: still a draft at Gmail, so it is an unsent draft here');
    }

    public function testAGmailDraftThatGmailNowLabelsSentKeepsItsSentAt(): void
    {
        $sent = $this->seed(gmailLabels: ['SENT']);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($sent), 'sent from Gmail: clearing it would make sent mail sendable again');
    }

    public function testAnOutlookDraftInDraftsLosesItsSentAt(): void
    {
        $draft = $this->seed(labels: [$this->drafts]);

        $this->migrate();

        self::assertNull($this->sentAtOf($draft), 'presence: still in Drafts at Outlook');
    }

    public function testAnOutlookDraftFiledInSentKeepsItsSentAt(): void
    {
        $sent = $this->seed(labels: [$this->sent]);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($sent), 'filed in Sent Items: it went out');
    }

    /** The flag is the other half of the test: a message in Drafts that is not flagged a draft is not one. */
    public function testAMessageInDraftsThatIsNotFlaggedADraftKeepsItsSentAt(): void
    {
        $message = $this->seed(labels: [$this->drafts], flags: []);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($message));
    }

    /** IMAP rows are no business of this: their stored flag never reads as a draft. */
    public function testARowFromNeitherProviderKeepsItsSentAt(): void
    {
        $message = $this->seed(labels: [$this->drafts], graph: false);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($message));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Applies the migration's own statements, in this test's transaction, so the
     * database it started with is the database it leaves.
     */
    private function migrate(): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261015130000.php';

        $migration = new Version20261015130000($this->connection, new NullLogger());
        $migration->up(new Schema());

        // Prepared, as Doctrine Migrations runs it, not executed raw: a raw
        // statement lets a jsonb `?` through that a prepared one reads as a
        // placeholder, and that difference once broke this migration on upgrade.
        foreach ($migration->getSql() as $query) {
            $this->connection->prepare($query->getStatement())->executeStatement();
        }

        $this->em->clear();
    }

    private function sentAtOf(Message $message): mixed
    {
        return $this->connection->fetchOne('SELECT sent_at FROM message WHERE id = :id', ['id' => $message->id]);
    }

    /**
     * A draft as the old builders stored it: `\Draft`, and its receivedAt as its
     * sentAt. Gmail's when $gmailLabels is given, Graph's otherwise, unless
     * $graph is false, which leaves it with neither provider's id.
     *
     * @param list<string>|null $gmailLabels
     * @param list<Label>       $labels
     * @param list<string>      $flags
     */
    private function seed(
        ?array $gmailLabels = null,
        array $labels = [],
        array $flags = [MessageFlag::DRAFT->value],
        bool $graph = true,
    ): Message {
        $at = new DateTimeImmutable('-1 hour');

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'A draft';
        $thread->normalizedSubject = 'a draft';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $at;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'A draft';
        $message->fromAddress    = 'drafts@example.test';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->hasAttachments = false;
        $message->flags          = $flags;
        $message->syncedAt       = new DateTimeImmutable();

        if (null !== $gmailLabels) {
            $message->gmailId       = uniqid('gmail-', true);
            $message->gmailLabelIds = $gmailLabels;
        } elseif (true === $graph) {
            $message->graphId = uniqid('graph-', true);
        }

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        // The prePersist listeners do not touch sentAt, but the claim of this
        // test rests on it being there, so it is checked rather than assumed.
        self::assertNotNull($this->sentAtOf($message), 'the fixture starts out with a sentAt');

        return $message;
    }

    private function seedLabel(string $name, LabelRole $role): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(): Account
    {
        $account                   = new Account();
        $account->usr              = $this->user;
        $account->name             = 'Drafts fixture';
        $account->email            = 'drafts-' . uniqid('', true) . '@example.test';
        $account->username         = uniqid('drafts-', true);
        $account->imapHost         = 'imap.example.test';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'draft-migration-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Draft';
        $user->nameLast  = 'Migration';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
