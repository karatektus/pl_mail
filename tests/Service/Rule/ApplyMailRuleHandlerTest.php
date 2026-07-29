<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\RuleRunState;
use App\Entity\Account;
use App\Entity\Label;
use App\Entity\MailRule;
use App\Entity\Message;
use App\Entity\User;
use App\Infrastructure\Messaging\Handler\ApplyMailRuleHandler;
use App\Infrastructure\Messaging\Message\ApplyMailRuleMessage;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\MessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Apply to existing mail" walks the whole mailbox, so the two things that can
 * go badly wrong are both about the walk: a cursor that fails to advance loops
 * forever, and one that advances too far skips messages silently.
 *
 * The e2e suite cannot reach this — the test environment's messenger transport
 * is in-memory://, so a dispatched job is never handled there.
 */
final class ApplyMailRuleHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $messages;
    private User $user;
    private Account $account;
    private Label $label;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->messages = $container->get(MessageRepository::class);

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

    /**
     * Paging must reach every match and then stop. A batch size smaller than
     * the corpus is the point — it forces several turns of the loop.
     */
    public function testKeysetWalkVisitsEveryMatchExactlyOnce(): void
    {
        $filter = new EmailFilterCompiler()->compile(['subject' => 'walk me']);

        $seen = [];
        $afterId = 0;
        $turns = 0;

        while (true) {
            $ids = $this->messages->findIdsMatchingForUser($this->user, $filter, $afterId, 2);

            if (0 === count($ids)) {
                break;
            }

            self::assertLessThan(20, ++$turns, 'The walk did not terminate — the cursor is not advancing.');

            $seen = array_merge($seen, $ids);
            $afterId = $ids[count($ids) - 1];
        }

        self::assertCount(7, $seen, 'Every matching message should be visited.');
        self::assertSame($seen, array_unique($seen), 'No message should be visited twice.');
        self::assertSame($seen, array_values(array_unique(array_map('intval', $seen))));
    }

    /**
     * The state machine is what the UI reads after a reload, so it has to end
     * up Completed with a truthful count — not merely "not busy".
     */
    public function testRunRecordsProgressAndCompletes(): void
    {
        $rule = new MailRule();
        $rule->usr = $this->user;
        $rule->name = 'Walkers';
        $rule->conditions = ['subject' => 'walk me'];
        $rule->actions = [['type' => 'applyLabel', 'labelId' => $this->label->id]];
        $this->em->persist($rule);
        $this->em->flush();

        self::assertSame(RuleRunState::Idle, $rule->runState);

        $container = self::getContainer();
        $handler = $container->get(ApplyMailRuleHandler::class);

        $handler(new ApplyMailRuleMessage((int) $rule->id));

        self::assertSame(RuleRunState::Completed, $rule->runState);
        self::assertSame(7, $rule->runProcessed, 'Every matching message should be counted.');
        self::assertNotNull($rule->runStartedAt);
        self::assertNotNull($rule->runFinishedAt);

        // And the action actually landed, on every match.
        $labelled = 0;

        foreach ($this->messages->findByIds($this->matchingIds()) as $message) {
            if (true === $message->getLabels()->contains($this->label)) {
                $labelled++;
            }
        }

        self::assertSame(7, $labelled);
    }

    /**
     * @return list<int>
     */
    private function matchingIds(): array
    {
        return $this->messages->findIdsMatchingForUser(
            $this->user,
            new EmailFilterCompiler()->compile(['subject' => 'walk me']),
            0,
            100,
        );
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user
            ->setEmail('rule-run-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Rule')
            ->setNameLast('Runner')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account
            ->setUsr($this->user)
            ->setEmail('Rule Runner')
            ->setUsername('rule-runner@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($this->account);

        $this->label = new Label();
        $this->label->setUsr($this->user)->setName('Walked');
        $this->em->persist($this->label);

        // Seven matches and three that must be left alone.
        for ($i = 0; $i < 7; $i++) {
            $this->message(sprintf('Please walk me %d', $i));
        }

        for ($i = 0; $i < 3; $i++) {
            $this->message(sprintf('Leave me alone %d', $i));
        }

        $this->em->flush();
    }

    private function message(string $subject): void
    {
        $message = new Message();
        $message
            ->setAccount($this->account)
            ->setSubject($subject)
            ->setFromAddress('sender@example.test')
            ->setFromName('Sender')
            ->setBodyText('body')
            ->setReceivedAt(new \DateTimeImmutable('2026-07-01 12:00:00'))
            ->setHasAttachments(false);

        $this->em->persist($message);
    }
}
