<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Jmap\State\ChangeLogRepository;
use App\Jmap\State\StateManager;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\SenderAuthentication;
use App\Service\Rule\RuleActionExecutor;
use App\Service\Rule\RuleCategoryFiler;
use App\Tests\Support\Mail\BuildsThreaderWithoutDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * That the "fileInCategory" action is wired into the executor at all.
 *
 * RuleCategoryFilerTest holds the policy — who may be filed, and into what. This
 * is the other half: a rule carrying the action reaches that policy, and does
 * nothing else on the way. In particular it sends nothing to the provider,
 * because filing is a tab and a tab exists only here; the bus is a stub that
 * fails the test on any dispatch.
 */
final class RuleActionExecutorFileInCategoryTest extends TestCase
{
    use BuildsThreaderWithoutDatabase;

    public function testTheActionIsOneOfTheTypesARuleMayCarry(): void
    {
        self::assertContains(RuleActionExecutor::FILE_IN_CATEGORY, RuleActionExecutor::TYPES);
        self::assertSame('fileInCategory', RuleActionExecutor::FILE_IN_CATEGORY);
    }

    public function testARuleCarryingTheActionFilesTheMessageAndSendsNothingToTheProvider(): void
    {
        $owner          = new User();
        $category       = new InboxCategory();
        $category->usr  = $owner;
        $category->name = 'Shipping';

        $rule          = new MailRule();
        $rule->usr     = $owner;
        $rule->actions = [['type' => 'fileInCategory', 'categoryId' => 3]];

        $message = $this->message($owner);

        $this->executor($category)->execute($rule, $message);

        self::assertSame($category, $message->ruleCategory);
        self::assertSame($category, $message->thread?->userCategory);
        self::assertCount(0, $message->labels, 'filing into a category changed the message\'s labels');
    }

    public function testTheSameRuleDoesNothingWhenTheCategoryIsGone(): void
    {
        $owner = new User();

        $rule          = new MailRule();
        $rule->usr     = $owner;
        $rule->actions = [['type' => 'fileInCategory', 'categoryId' => 3]];

        $message = $this->message($owner);

        $this->executor(null)->execute($rule, $message);

        self::assertNull($message->ruleCategory);
        self::assertNull($message->thread?->userCategory);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function executor(?InboxCategory $found): RuleActionExecutor
    {
        $categories = $this->createStub(InboxCategoryRepository::class);
        $categories->method('find')->willReturn($found);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            self::fail('filing into a category dispatched ' . $message::class);
        });

        return new RuleActionExecutor(
            $this->createStub(LabelRepository::class),
            new LabelResolver(
                $this->createStub(LabelRepository::class),
                $this->createStub(LabelBindingRepository::class),
                $this->createStub(EntityManagerInterface::class),
                new StateManager(
                    $this->createStub(EntityManagerInterface::class),
                    new ChangeLogRepository($this->createStub(ManagerRegistry::class)),
                ),
            ),
            new LabelChangePropagator(
                $bus,
                $this->createStub(MailboxRepository::class),
                $this->createStub(LabelRepository::class),
                new GraphLabelPolicy(),
                new NullLogger(),
            ),
            new ThreadLabelSynchronizer(),
            $this->createStub(IntegrationRepository::class),
            $bus,
            new NullLogger(),
            new RuleCategoryFiler(
                $categories,
                new SenderAuthentication(),
                $this->threaderWithoutDatabase(),
                new NullLogger(),
            ),
        );
    }

    private function message(User $owner): Message
    {
        $account                = new Account();
        $account->usr           = $owner;
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;

        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = 'noreply@mondialrelay.fr';
        $message->category    = MessageCategory::Promotions;
        $message->receivedAt  = new \DateTimeImmutable('2026-07-28 12:00:00');
        $message->headers     = ['authentication-results' => 'spf=pass smtp.mailfrom=mondialrelay.fr; dmarc=pass action=none header.from=mondialrelay.fr'];

        $thread                  = new MessageThread();
        $thread->category        = MessageCategory::Promotions;
        $thread->lastMessageAt   = new \DateTimeImmutable('2026-07-28 12:00:00');
        $thread->messageCount    = 1;
        $thread->unreadCount     = 0;
        $thread->attachmentCount = 0;
        $message->thread         = $thread;

        return $message;
    }
}
