<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030100000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That a rule which applied a folder label becomes a "Move to folder" rule
 * (#221 step 3) — and only where the label is a folder on every account the
 * rule covers, so a Gmail account that was only ever tagged is not now also
 * archived.
 *
 * Run against rows, not against the SQL text: the failure to guard against is
 * a statement that is valid, applies, and rewrites the wrong rules.
 */
final class MoveToFolderRuleMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'rule-migration-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Rule';
        $this->user->nameLast  = 'Migration';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testApplyingAFolderBecomesMovingThere(): void
    {
        $imap    = $this->account();
        $folder  = $this->label('Receipts', $imap, folder: true);
        $tag     = $this->label('Later', $imap, folder: false);
        $rule    = $this->rule(null, [
            ['type' => 'markRead'],
            ['type' => 'applyLabel', 'labelId' => $folder->id],
            ['type' => 'applyLabel', 'labelId' => $tag->id],
        ]);

        $this->migrate();

        self::assertSame([
            ['type' => 'markRead'],
            ['type' => 'moveToFolder', 'labelId' => $folder->id],
            ['type' => 'applyLabel', 'labelId' => $tag->id],
        ], $this->actionsOf($rule), 'the folder is moved to, the tag still applied, and the order kept');
    }

    public function testARuleOverAnAccountWhereTheLabelIsATagIsLeftAlone(): void
    {
        $imap   = $this->account();
        $gmail  = $this->account(gmail: true);
        $folder = $this->label('Receipts', $imap, folder: true);

        $everywhere = $this->rule(null, [['type' => 'applyLabel', 'labelId' => $folder->id]]);
        $onGmail    = $this->rule($gmail, [['type' => 'applyLabel', 'labelId' => $folder->id]]);
        $onImap     = $this->rule($imap, [['type' => 'applyLabel', 'labelId' => $folder->id]]);

        $this->migrate();

        self::assertSame('applyLabel', $this->actionsOf($everywhere)[0]['type'], 'on the Gmail account it only ever tagged');
        self::assertSame('applyLabel', $this->actionsOf($onGmail)[0]['type']);
        self::assertSame('moveToFolder', $this->actionsOf($onImap)[0]['type'], 'a rule on the IMAP account alone moved, and still does');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function migrate(): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261030100000.php';

        $migration = new Version20261030100000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $this->em->clear();
    }

    /** @return list<array<string, mixed>> */
    private function actionsOf(MailRule $rule): array
    {
        $raw = $this->connection->fetchOne('SELECT actions FROM mail_rule WHERE id = ?', [$rule->id]);

        return json_decode((string) $raw, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $actions */
    private function rule(?Account $account, array $actions): MailRule
    {
        $rule          = new MailRule();
        $rule->usr     = $this->user;
        $rule->account = $account;
        $rule->name    = 'Rule';
        $rule->actions = $actions;
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    private function account(bool $gmail = false): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->email          = 'rule-migration-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = $gmail ? null : 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = $gmail ? AuthType::OAuth2->value : 'password';
        $account->oauthProvider  = $gmail ? MailProvider::Google->value : null;
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function label(string $name, Account $on, bool $folder): Label
    {
        $label       = new Label();
        $label->usr  = $this->user;
        $label->name = $name;
        $this->em->persist($label);

        $binding          = new LabelBinding();
        $binding->label   = $label;
        $binding->account = $on;

        if (true === $folder) {
            $mailbox                = new Mailbox();
            $mailbox->account       = $on;
            $mailbox->name          = $name;
            $mailbox->fullPath      = $name;
            $mailbox->delimiter     = '/';
            $mailbox->isSyncEnabled = true;
            $this->em->persist($mailbox);

            $binding->mailbox = $mailbox;
        }

        $this->em->persist($binding);
        $this->em->flush();

        return $label;
    }
}
