<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Mail\Account;
use App\Entity\Integration\Integration;
use App\Entity\Rule\MailRule;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\UploadAttachmentsMessage;
use App\Jmap\State\StateManager;
use App\Jmap\State\ChangeLogRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Rule\RuleActionExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The saveToIntegration action.
 *
 * The contract worth protecting is that this action does no I/O. It runs
 * inside the sync loop, once per rule per batch of newly arrived mail, so an
 * upload here would put a network round trip between mail arriving and it
 * being visible — and an unreachable service would stall the sync itself
 * rather than just failing its own job. Everything below is a variation on
 * "did it dispatch, or did it correctly decline to".
 */
final class RuleActionExecutorIntegrationActionTest extends TestCase
{
    private User $user;

    /** @var list<UploadAttachmentsMessage> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $this->dispatched = [];
    }

    public function testDispatchesAnUploadJobForAMessageWithAttachments(): void
    {
        $integration = $this->integration();
        $executor = $this->executor($integration);

        $executor->execute(
            $this->rule(['type' => RuleActionExecutor::SAVE_TO_INTEGRATION, 'integrationId' => 7]),
            $this->message(hasAttachments: true),
        );

        self::assertCount(1, $this->dispatched);
        self::assertSame(7, $this->dispatched[0]->integrationId);
        self::assertNull($this->dispatched[0]->folder);
    }

    public function testPassesTheConfiguredFolderThrough(): void
    {
        $executor = $this->executor($this->integration());

        $executor->execute(
            $this->rule([
                'type'          => RuleActionExecutor::SAVE_TO_INTEGRATION,
                'integrationId' => 7,
                'folder'        => 'Mail/Invoices',
            ]),
            $this->message(hasAttachments: true),
        );

        self::assertSame('Mail/Invoices', $this->dispatched[0]->folder);
    }

    public function testSkipsMessagesWithNoAttachments(): void
    {
        $executor = $this->executor($this->integration());

        $executor->execute(
            $this->rule(['type' => RuleActionExecutor::SAVE_TO_INTEGRATION, 'integrationId' => 7]),
            $this->message(hasAttachments: false),
        );

        // Filtered before dispatch rather than in the handler: a rule matching
        // mostly plain mail would otherwise fill the queue with no-op jobs.
        self::assertSame([], $this->dispatched);
    }

    public function testRefusesAnIntegrationBelongingToSomeoneElse(): void
    {
        $stranger = new Integration(new User(), Provider::Nextcloud, 'Not yours');
        $executor = $this->executor($stranger);

        $executor->execute(
            $this->rule(['type' => RuleActionExecutor::SAVE_TO_INTEGRATION, 'integrationId' => 7]),
            $this->message(hasAttachments: true),
        );

        // Re-checked at run time, not trusted from the stored action — the
        // connection may have changed hands since the rule was written.
        self::assertSame([], $this->dispatched);
    }

    public function testSkipsAPausedConnection(): void
    {
        $paused = $this->integration();
        $paused->isActive = false;

        $this->executor($paused)->execute(
            $this->rule(['type' => RuleActionExecutor::SAVE_TO_INTEGRATION, 'integrationId' => 7]),
            $this->message(hasAttachments: true),
        );

        self::assertSame([], $this->dispatched);
    }

    public function testSkipsAnActionWithNoTarget(): void
    {
        $this->executor($this->integration())->execute(
            $this->rule(['type' => RuleActionExecutor::SAVE_TO_INTEGRATION]),
            $this->message(hasAttachments: true),
        );

        self::assertSame([], $this->dispatched);
    }

    public function testSkipsWhenTheConnectionHasBeenDeleted(): void
    {
        $this->executor(null)->execute(
            $this->rule(['type' => RuleActionExecutor::SAVE_TO_INTEGRATION, 'integrationId' => 7]),
            $this->message(hasAttachments: true),
        );

        self::assertSame([], $this->dispatched);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function executor(?Integration $found): RuleActionExecutor
    {
        $integrationRepository = $this->createStub(IntegrationRepository::class);
        $integrationRepository->method('find')->willReturn($found);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                if ($message instanceof UploadAttachmentsMessage) {
                    $this->dispatched[] = $message;
                }

                return new Envelope($message);
            },
        );

        return new RuleActionExecutor(
            $this->createStub(LabelRepository::class),
            // The label collaborators are final, so they are built for real
            // rather than doubled. None of them is reached on the
            // saveToIntegration path — they are here to satisfy the
            // constructor, and their own dependencies are stubs.
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
                $this->createStub(MessageBusInterface::class),
                $this->createStub(MailboxRepository::class),
                $this->createStub(LabelRepository::class),
                new GraphLabelPolicy(),
                new NullLogger(),
            ),
            new ThreadLabelSynchronizer(),
            $integrationRepository,
            $bus,
            new NullLogger(),
        );
    }

    private function integration(): Integration
    {
        return new Integration($this->user, Provider::Nextcloud, 'Home cloud');
    }

    /**
     * @param array<string,mixed> $action
     */
    private function rule(array $action): MailRule
    {
        $rule = new MailRule();
        $rule->usr = $this->user;
        $rule->actions = [$action];

        return $rule;
    }

    private function message(bool $hasAttachments): Message
    {
        $account = new Account();
        $account->usr = $this->user;

        $message = new Message();
        $message->account = $account;
        $message->hasAttachments = $hasAttachments;

        return $message;
    }
}
