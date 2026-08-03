<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Mapper;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Jmap\Mapper\EmailMapper;
use App\Jmap\Mapper\MailboxCounts;
use App\Jmap\Mapper\MailboxMapper;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Mail\MessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which id space Email.mailboxIds speaks.
 *
 * A JMAP Mailbox id is a per-account LabelBinding id, but the message<->label
 * join that backs mailboxIds stores user-scoped Label ids. Both are
 * autoincrement ints from separate tables, so an untranslated id is usually a
 * *valid-looking* id for some unrelated mailbox — the client renders a
 * plausible wrong answer instead of failing. Hence the round-trip test below:
 * it asserts the ids are usable, not merely that they are numbers.
 *
 * Runs against the database because the two id sequences only diverge once
 * real rows exist, which is precisely the condition under which the bug this
 * pins down was invisible.
 */
final class EmailMapperTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelBindingRepository $bindings;
    private MessageRepository $messages;
    private EmailMapper $mapper;

    private Account $account;
    private Account $otherAccount;

    private Label $inbox;
    private Label $work;
    /** Bound on $otherAccount only — the cross-account case. */
    private Label $personal;

    private LabelBinding $inboxBinding;
    private LabelBinding $workBinding;

    private Message $message;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->bindings = $container->get(LabelBindingRepository::class);
        $this->messages = $container->get(MessageRepository::class);
        $this->mapper = new EmailMapper();

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testMailboxIdsAreBindingIdsNotLabelIds(): void
    {
        $ids = $this->mailboxIds($this->account);

        self::assertSame(
            $this->sorted([(string) $this->inboxBinding->id, (string) $this->workBinding->id]),
            $this->sorted(array_keys($ids)),
        );
    }

    /**
     * The failure this whole exercise is about. Labels are user-scoped, so the
     * same label id turns up under every account; only the binding is
     * per-account. If the untranslated label id were emitted, both accounts
     * would report the same mailboxIds for their own messages.
     */
    public function testTheSameLabelYieldsDifferentIdsInDifferentAccounts(): void
    {
        $here = $this->mailboxIds($this->account);
        $there = $this->mailboxIds($this->otherAccount, $this->otherMessage());

        self::assertNotSame($this->sorted(array_keys($here)), $this->sorted(array_keys($there)));
        self::assertNotSame((string) $this->inbox->id, (string) $this->inboxBinding->id);
    }

    /**
     * A message can carry a user-scoped label that this account has no binding
     * for. Emitting its label id would name a mailbox the client cannot fetch,
     * so it is left out entirely.
     *
     * Asserted as "the set does not change" rather than "this id is absent":
     * the label's id can perfectly well collide with a binding id that
     * legitimately belongs in the map, which is the whole reason the untranslated
     * ids were never noticed. An absence assertion fails at the mercy of
     * whatever the sequences happen to be when the suite runs.
     */
    public function testLabelWithNoBindingInThisAccountIsOmitted(): void
    {
        $before = $this->sorted(array_keys($this->mailboxIds($this->account)));

        $this->message->addLabel($this->personal);
        $this->em->flush();

        self::assertSame($before, $this->sorted(array_keys($this->mailboxIds($this->account))));
    }

    /**
     * Every id the client reads back must be one it can pass to inMailbox.
     * This is the assertion that would have caught the original defect: the
     * untranslated ids decoded fine and simply selected the wrong rows.
     */
    public function testEveryEmittedIdIsAUsableInMailboxFilter(): void
    {
        $compiler = new EmailFilterCompiler();
        $messageId = (int) $this->message->id;

        foreach (array_keys($this->mailboxIds($this->account)) as $mailboxId) {
            $matched = $this->messages->matchingIds(
                [$messageId],
                $compiler->compile(['inMailbox' => $mailboxId]),
            );

            self::assertSame([$messageId], $matched, sprintf('inMailbox "%s" did not select the message it came from.', $mailboxId));
        }
    }

    /**
     * RFC 8621 models set membership as a map, and an empty one must serialise
     * as {} — an empty PHP array would encode as [] and break clients that
     * decode the property as an object.
     */
    public function testEmptyMailboxIdsSerialisesAsAnObject(): void
    {
        $bare = new Message();
        $bare->account = $this->account;
        $bare->subject = 'No labels';
        $bare->fromAddress = 'nobody@example.test';
        $bare->fromName = 'Nobody';
        $bare->bodyText = 'body';
        $bare->receivedAt = new \DateTimeImmutable('2026-07-20 12:00:00');
        $bare->hasAttachments = false;
        $this->em->persist($bare);
        $this->em->flush();

        $mapped = $this->mapper->toJmap($bare, null, false, false, null, $this->bindingMap($this->account));

        self::assertInstanceOf(\stdClass::class, $mapped['mailboxIds']);
        self::assertSame('{}', json_encode($mapped['mailboxIds']));
    }

    /**
     * The binding id is the identity, but it is per-account by necessity — so
     * Mailbox also carries the user-scoped label id, which is what lets a
     * client collapse one label across three accounts into one sidebar row.
     */
    public function testMailboxCarriesTheUserScopedLabelId(): void
    {
        $mapped = (new MailboxMapper())->toJmap($this->inboxBinding, new MailboxCounts());

        self::assertSame((string) $this->inboxBinding->id, $mapped['id']);
        self::assertSame((string) $this->inbox->id, $mapped['labelId']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,bool>
     */
    private function mailboxIds(Account $account, ?Message $message = null): array
    {
        $mapped = $this->mapper->toJmap(
            $message ?? $this->message,
            ['mailboxIds'],
            false,
            false,
            null,
            $this->bindingMap($account),
        );

        $ids = $mapped['mailboxIds'];

        return $ids instanceof \stdClass ? [] : $ids;
    }

    /**
     * @return array<int,int>
     */
    private function bindingMap(Account $account): array
    {
        return $this->bindings->bindingIdsByLabelId((int) $account->id);
    }

    /**
     * PHP silently casts numeric-string array keys back to ints, so array_keys
     * on a mailboxIds map hands back ints even though the wire form is
     * {"13": true}. Normalising here keeps the assertions about the id values
     * rather than about that quirk.
     *
     * @param list<string|int> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        $ids = array_map('strval', $ids);
        sort($ids);

        return $ids;
    }

    private function otherMessage(): Message
    {
        $message = new Message();
        $message->account = $this->otherAccount;
        $message->subject = 'Over here';
        $message->fromAddress = 'someone@example.test';
        $message->fromName = 'Someone';
        $message->bodyText = 'body';
        $message->receivedAt = new \DateTimeImmutable('2026-07-18 12:00:00');
        $message->hasAttachments = false;
        $message->addLabel($this->inbox);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'mapper-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Mapper';
        $user->nameLast = 'Corpus';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $this->account = $this->account($user, 'mapper-one@example.test');
        $this->otherAccount = $this->account($user, 'mapper-two@example.test');

        $this->inbox = $this->label($user, 'Inbox', LabelRole::Inbox);
        $this->work = $this->label($user, 'Work');
        $this->personal = $this->label($user, 'Personal');

        // Deliberately lopsided: Inbox is bound on both accounts, Work only
        // here, Personal only there. Nothing in the fixture lets label id and
        // binding id line up by accident.
        $this->inboxBinding = $this->binding($this->inbox, $this->account);
        $this->workBinding = $this->binding($this->work, $this->account);
        $this->binding($this->inbox, $this->otherAccount);
        $this->binding($this->personal, $this->otherAccount);

        $this->message = new Message();
        $this->message->account = $this->account;
        $this->message->subject = 'Invoice 42';
        $this->message->fromAddress = 'billing@acme.test';
        $this->message->fromName = 'Acme Billing';
        $this->message->bodyText = 'Please arrange a wire transfer.';
        $this->message->receivedAt = new \DateTimeImmutable('2026-07-15 10:00:00');
        $this->message->hasAttachments = false;
        $this->message->addLabel($this->inbox);
        $this->message->addLabel($this->work);
        $this->em->persist($this->message);

        $this->em->flush();
    }

    private function account(User $user, string $username): Account
    {
        $account = new Account();
        $account->usr = $user;
        $account->email = 'Mapper Corpus';
        $account->username = $username;
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

        return $account;
    }

    private function label(User $user, string $name, ?LabelRole $role = null): Label
    {
        $label = new Label();
        $label->usr  = $user;
        $label->name = $name;
        $label->role = $role;
        $this->em->persist($label);

        return $label;
    }

    private function binding(Label $label, Account $account): LabelBinding
    {
        $binding = new LabelBinding();
        $binding->label = $label;
        $binding->account = $account;
        $this->em->persist($binding);

        return $binding;
    }
}
