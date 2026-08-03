<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Rule\FilterDescriber;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sentence under a rule.
 *
 * It is not decoration: reading a filter back in words is how somebody catches
 * an AND that should have been an OR, because the tree looks equally correct
 * either way. A sentence that is subtly wrong is worse than none at all — it is
 * a rule the author has now verified.
 *
 * The empty-conditions case is the one that was wrong. "If this is any message"
 * read the same whether the rule covered one account or every account, which is
 * precisely the case where the account is the entire filter.
 */
final class FilterDescriberTest extends KernelTestCase
{
    private FilterDescriber $describer;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** One action, so the sentence has a "then" half without a label lookup. */
    private const array ARCHIVE = [['type' => 'archive']];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->describer  = $container->get(FilterDescriber::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAConditionIsRestatedInWords(): void
    {
        $sentence = $this->describer->describe(['subject' => 'invoice'], self::ARCHIVE, $this->user);

        self::assertStringContainsString('Subject contains invoice', $sentence);
    }

    /**
     * The distinction the sentence exists for. A group reads as "all of" or
     * "any of", and the words have to differ or the reader cannot check the
     * thing they are reading it to check.
     */
    public function testAndAndOrReadDifferently(): void
    {
        $tree = static fn (string $operator): array => [
            'operator'   => $operator,
            'conditions' => [['subject' => 'invoice'], ['from' => 'acme']],
        ];

        $and = $this->describer->describe($tree('AND'), self::ARCHIVE, $this->user);
        $or  = $this->describer->describe($tree('OR'), self::ARCHIVE, $this->user);

        self::assertStringContainsString(' and ', $and);
        self::assertStringContainsString(' or ', $or);
        self::assertNotSame($and, $or);
    }

    /** NOT over several conditions means "none of", which is not "not all of". */
    public function testNotIsSpelledOut(): void
    {
        $sentence = $this->describer->describe(
            ['operator' => 'NOT', 'conditions' => [['subject' => 'invoice'], ['from' => 'acme']]],
            self::ARCHIVE,
            $this->user,
        );

        self::assertStringContainsString('none of', mb_strtolower($sentence));
    }

    /**
     * No conditions is a rule that acts on everything it is scoped to, so the
     * sentence has to say which scope. Unscoped it is the whole mailbox;
     * scoped, the account is the subject rather than a footnote.
     */
    public function testNoConditionsSaysWhichAccount(): void
    {
        $everywhere = $this->describer->describe([], self::ARCHIVE, $this->user);
        $oneAccount = $this->describer->describe([], self::ARCHIVE, $this->user, $this->seedAccount());

        self::assertStringContainsString('any message', $everywhere);
        self::assertStringNotContainsString('you@example.test', $everywhere);

        self::assertStringContainsString('you@example.test', $oneAccount);
    }

    public function testAnAccountNarrowsASentenceThatHasConditionsToo(): void
    {
        $sentence = $this->describer->describe(
            ['subject' => 'invoice'],
            self::ARCHIVE,
            $this->user,
            $this->seedAccount(),
        );

        self::assertStringContainsString('Subject contains invoice', $sentence);
        self::assertStringContainsString('you@example.test', $sentence);
    }

    /**
     * A rule with no actions is a legitimate half-finished state in the editor
     * — the count and the sentence both render before the action is added — so
     * it has to describe itself rather than read as a complete rule.
     */
    public function testARuleWithNoActionsSaysSo(): void
    {
        $sentence = $this->describer->describe(['subject' => 'invoice'], [], $this->user);

        self::assertStringContainsString('Subject contains invoice', $sentence);
        self::assertStringContainsString('no actions', mb_strtolower($sentence));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Describer fixture';
        $account->email          = 'you@example.test';
        $account->username       = uniqid('describer-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'describer-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Describer';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
