<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\DTO\Push\NotifyScope;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;

/**
 * A label made or renamed from under one account is that account's alone
 * (#690).
 *
 * The case from the issue: "Sports" on a personal account and "Sports" on a
 * club account mean different things, and the sync makes them one plMail label
 * because it finds labels by name. Renamed from under the club account, the
 * club's binding — and the club's mail — move to a label of their own; the
 * personal account keeps "Sports". Renamed from the merged list, it is still
 * one label renamed everywhere, which is what that list means.
 *
 * Read back from the tables rather than the entities, because the form writes
 * the new name onto the shared label before the split puts it back.
 */
final class LabelOnOneAccountTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private Account $club;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        // Before anything reaches for it: a save publishes the label lists.
        $container->set(HubInterface::class, new RecordingHub());

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->club    = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->user->labelsByAccount = true;
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testANewLabelFromUnderAnAccountIsOnThatAccountOnly(): void
    {
        $this->submit('/labels/new?account=' . $this->club->id, 'Training');

        self::assertResponseIsSuccessful();

        $id = (int) $this->connection->fetchOne('SELECT id FROM label WHERE usr_id = ? AND name = ?', [$this->user->id, 'Training']);

        self::assertSame([(int) $this->club->id], $this->accountsOf($id));
    }

    public function testRenamingASharedLabelUnderOneAccountGivesThatAccountItsOwn(): void
    {
        $sports       = $this->labelOn('Sports', $this->account, $this->club);
        $clubBinding  = (int) $sports->bindingFor($this->club)?->id;
        $personal     = $this->threadOn($this->account, 'Five-a-side on Sunday', $sports);
        $club         = $this->threadOn($this->club, 'Club AGM', $sports);

        $crawler = $this->client->request('GET', '/labels/' . $sports->id . '/edit?account=' . $this->club->id);

        self::assertStringContainsString($this->account->displayAddress, $crawler->filter('[data-label-account-note]')->text(), 'the dialog says who keeps the old name');

        $this->submit('/labels/' . $sports->id . '/edit?account=' . $this->club->id, 'Club sports');

        self::assertResponseIsSuccessful();

        $clubLabel = (int) $this->connection->fetchOne('SELECT id FROM label WHERE usr_id = ? AND name = ?', [$this->user->id, 'Club sports']);

        self::assertSame('Sports', $this->nameOf((int) $sports->id), 'the shared label keeps its name');
        self::assertSame([(int) $this->account->id], $this->accountsOf((int) $sports->id));
        self::assertSame([(int) $this->club->id], $this->accountsOf($clubLabel));
        self::assertSame($clubBinding, (int) $this->connection->fetchOne('SELECT id FROM label_binding WHERE label_id = ?', [$clubLabel]), 'the binding moved, so its remote id and JMAP id stay');

        self::assertSame([(int) $sports->id], $this->labelsOfThread($personal));
        self::assertSame([$clubLabel], $this->labelsOfThread($club));
        self::assertSame([$clubLabel], $this->labelsOfMessages($club));
    }

    /** The merged list's rename still means every account. */
    public function testRenamingWithoutAnAccountStillRenamesItEverywhere(): void
    {
        $sports = $this->labelOn('Sports', $this->account, $this->club);

        $this->submit('/labels/' . $sports->id . '/edit', 'Games');

        self::assertResponseIsSuccessful();
        self::assertSame('Games', $this->nameOf((int) $sports->id));
        self::assertSame([(int) $this->account->id, (int) $this->club->id], $this->accountsOf((int) $sports->id));
    }

    /**
     * Labels under it follow on that account, so the club's tree keeps its
     * shape; the personal account's "Sports/Football" stays where it was.
     */
    public function testALabelUnderItFollowsOnThatAccount(): void
    {
        $sports   = $this->labelOn('Sports', $this->account, $this->club);
        $football = $this->labelOn('Football', $this->account, $this->club);
        $sports->addChild($football);
        $this->em->flush();

        $this->submit('/labels/' . $sports->id . '/edit?account=' . $this->club->id, 'Club');

        self::assertResponseIsSuccessful();

        $club         = (int) $this->connection->fetchOne('SELECT id FROM label WHERE usr_id = ? AND name = ?', [$this->user->id, 'Club']);
        $clubFootball = (int) $this->connection->fetchOne('SELECT id FROM label WHERE parent_id = ? AND name = ?', [$club, 'Football']);

        self::assertNotSame(0, $clubFootball, 'Football came along under Club');
        self::assertSame([(int) $this->club->id], $this->accountsOf($clubFootball));
        self::assertSame([(int) $this->account->id], $this->accountsOf((int) $football->id));
        self::assertSame((int) $sports->id, (int) $this->connection->fetchOne('SELECT parent_id FROM label WHERE id = ?', [$football->id]));
    }

    /**
     * What named the old label for that account moves with it: a rule limited
     * to the club account, and a notification switch. A rule for every
     * account keeps the label every other account still has.
     */
    public function testRulesForThatAccountAndNotificationsFollow(): void
    {
        $sports    = $this->labelOn('Sports', $this->account, $this->club);
        $clubRule  = $this->rule($sports, $this->club);
        $everyRule = $this->rule($sports, null);

        $this->user->notifyScopes = [NotifyScope::PRIMARY, NotifyScope::label((int) $sports->id)];
        $this->em->flush();

        $this->submit('/labels/' . $sports->id . '/edit?account=' . $this->club->id, 'Club sports');

        $clubLabel = (int) $this->connection->fetchOne('SELECT id FROM label WHERE usr_id = ? AND name = ?', [$this->user->id, 'Club sports']);

        self::assertSame($clubLabel, $this->ruleLabel($clubRule));
        self::assertSame((int) $sports->id, $this->ruleLabel($everyRule));

        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->id);

        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->notifiesFor(NotifyScope::label((int) $sports->id)));
        self::assertTrue($user->notifiesFor(NotifyScope::label($clubLabel)));
    }

    /**
     * A second label, so a name that is the old one in other capitals is
     * taken — "sports" beside "Sports" would be two labels the name rule
     * treats as one.
     */
    public function testANameTakenByTheLabelItselfIsRefused(): void
    {
        $sports = $this->labelOn('Sports', $this->account, $this->club);

        $this->submit('/labels/' . $sports->id . '/edit?account=' . $this->club->id, 'sports');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([(int) $this->account->id, (int) $this->club->id], $this->accountsOf((int) $sports->id));
    }

    public function testAnotherUsersAccountIsRefused(): void
    {
        $stranger       = $this->seedUser();
        $theirs         = $this->seedAccount();
        $theirs->usr    = $stranger;
        $this->em->flush();

        $this->client->request('GET', '/labels/new?account=' . $theirs->id);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function labelOn(string $name, Account ...$accounts): Label
    {
        $label = $this->seedLabel($name);

        foreach ($accounts as $account) {
            $binding          = new LabelBinding();
            $binding->label   = $label;
            $binding->account = $account;
            $this->em->persist($binding);
        }

        $this->em->flush();

        return $label;
    }

    private function threadOn(Account $account, string $subject, Label $label): MessageThread
    {
        // thread() seeds on $this->account; pointed at the one asked for, and
        // put back.
        $previous      = $this->account;
        $this->account = $account;

        $thread = $this->thread($subject);
        $thread->addLabel($label);

        foreach ($thread->messages as $message) {
            $message->addLabel($label);
        }

        $this->em->flush();
        $this->account = $previous;

        return $thread;
    }

    private function rule(Label $label, ?Account $account): MailRule
    {
        $rule             = new MailRule();
        $rule->usr        = $this->user;
        $rule->account    = $account;
        $rule->name       = 'Sports mail';
        $rule->conditions = ['from' => 'club@example.test'];
        $rule->actions    = [['type' => 'label', 'labelId' => $label->id]];

        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    /**
     * Fetched and submitted rather than hand-built, so the CSRF token and the
     * parent and colour fields are exactly what a person's click sends.
     */
    private function submit(string $path, string $name): void
    {
        $crawler = $this->client->request('GET', $path);

        $form                = $crawler->filter('form')->form();
        $form['label[name]'] = $name;

        $this->client->submit($form);
    }

    private function nameOf(int $labelId): string
    {
        return (string) $this->connection->fetchOne('SELECT name FROM label WHERE id = ?', [$labelId]);
    }

    /** @return list<int> */
    private function accountsOf(int $labelId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT account_id FROM label_binding WHERE label_id = ? ORDER BY account_id', [$labelId]));
    }

    /** @return list<int> the custom labels on the thread */
    private function labelsOfThread(MessageThread $thread): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT tl.label_id FROM thread_label tl JOIN label l ON l.id = tl.label_id WHERE tl.message_thread_id = ? AND l.role IS NULL',
            [$thread->id],
        ));
    }

    /** @return list<int> the custom labels on the thread's messages */
    private function labelsOfMessages(MessageThread $thread): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT ml.label_id FROM message_label ml JOIN message m ON m.id = ml.message_id JOIN label l ON l.id = ml.label_id WHERE m.thread_id = ? AND l.role IS NULL',
            [$thread->id],
        ));
    }

    private function ruleLabel(MailRule $rule): int
    {
        $actions = json_decode((string) $this->connection->fetchOne('SELECT actions FROM mail_rule WHERE id = ?', [$rule->id]), true);

        return (int) $actions[0]['labelId'];
    }
}
