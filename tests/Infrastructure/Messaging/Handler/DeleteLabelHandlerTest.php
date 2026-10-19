<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Exception\LabelDeleteRefusedException;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Handler\DeleteLabelHandler;
use App\Infrastructure\Messaging\Message\DeleteLabelMessage;
use App\Service\Label\LabelResolver;
use App\Service\Label\RemoteLabelRemover;
use App\Tests\Support\Mail\SeedsFolderLabels;
use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Deleting a folder-backed label: the mail moves first, the folder goes only
 * once the server says it is empty, and the label goes last (issue #40).
 *
 * The server is a double — RemoteLabelRemover is the one seam that talks to
 * one — so these pin the handler's order and its patience, not IMAP. What the
 * double answers is the question the real one asks the server: is there
 * anything left there.
 */
final class DeleteLabelHandlerTest extends KernelTestCase
{
    use SeedsFolderLabels;

    private Connection $connection;

    /** @var ArrayObject<int, object> */
    private ArrayObject $dispatched;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->dispatched    = new ArrayObject();

        $this->connection->beginTransaction();

        $this->seedFolderUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The mail is in the destination folder — on the row and in the label it
     * wears — and the label is still here: it must outlive its folder, or a
     * sync in between brings it back.
     */
    public function testTheMovePhaseFilesTheMailAndKeepsTheLabel(): void
    {
        $label    = $this->folderLabel(['Old']);
        $receipts = $this->folderLabel(['Receipts']);
        $target   = $receipts->bindingFor($this->account)?->mailbox?->id;
        $first    = $this->messageIn($label, 'First');
        $second   = $this->messageIn($label, 'Second');
        $job      = $this->job($label, $receipts);
        $labelId  = $label->id;

        $this->handler(serverIsEmpty: true)(new DeleteLabelMessage((int) $job->id));

        $this->em->clear();

        foreach ([$first->id, $second->id] as $id) {
            $message = $this->em->find(Message::class, $id);
            $names   = $this->labelNames($message);

            self::assertContains('Receipts', $names);
            self::assertNotContains('Old', $names);
            self::assertSame($target, $message?->mailbox?->id, 'the row still names the folder being deleted');
        }

        self::assertNotNull($this->em->find(Label::class, $labelId), 'the label went before its folder');

        $fresh = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($fresh);
        self::assertSame(JobState::Running, $fresh->state);
        self::assertSame(2, $fresh->processed);

        // The server half is next, and later: the moves it waits for are on
        // another queue.
        $next = $this->dispatched[0] ?? null;

        self::assertInstanceOf(Envelope::class, $next);
        self::assertSame(DeleteLabelMessage::PHASE_REMOTE, $next->getMessage()->phase);
        self::assertNotNull($next->last(DelayStamp::class));
    }

    /**
     * Archive is a place with its own provider behaviour, reached through the
     * same move a drop on it uses.
     */
    public function testTheMailCanGoToTheArchive(): void
    {
        $label   = $this->folderLabel(['Old']);
        $message = $this->messageIn($label, 'Keep me');
        $job     = $this->job($label, $this->systemLabel(LabelRole::Archive));

        $this->handler(serverIsEmpty: true)(new DeleteLabelMessage((int) $job->id));

        $this->em->clear();

        self::assertContains('Archive', $this->labelNames($this->em->find(Message::class, $message->id)));
    }

    /**
     * The account has no Archive yet: it is made before anything moves, so the
     * moved rows have a folder to point at. Without it they stayed pointing at
     * the folder being deleted, and the delete waited on them for ever.
     */
    public function testAMissingArchiveIsCreatedBeforeTheMailMoves(): void
    {
        $label    = $this->folderLabel(['Old']);
        $prepared = new ArrayObject();
        $this->messageIn($label, 'Keep me');

        $job = $this->job($label, $this->systemLabel(LabelRole::Archive));

        $this->handler(serverIsEmpty: true, prepared: $prepared)(new DeleteLabelMessage((int) $job->id));

        self::assertSame(['archive'], $prepared->getArrayCopy());
    }

    /** A server that will not make the folder: nothing moves, the job says why. */
    public function testADestinationTheServerWillNotCreateMovesNothing(): void
    {
        $label   = $this->folderLabel(['Old']);
        $message = $this->messageIn($label, 'Stay put');
        $job     = $this->job($label, $this->systemLabel(LabelRole::Trash));

        $this->handler(serverIsEmpty: true, canCreate: false)(new DeleteLabelMessage((int) $job->id));

        $this->em->clear();

        $fresh = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($fresh);
        self::assertSame(JobState::Failed, $fresh->state);
        self::assertStringContainsString('Trash', (string) $fresh->failureReason);
        self::assertContains('Old', $this->labelNames($this->em->find(Message::class, $message->id)));
        self::assertCount(0, $this->dispatched);
    }

    /** Empty folders have nothing in flight to wait for. */
    public function testNothingToMoveGoesStraightToTheServer(): void
    {
        $label = $this->folderLabel(['Empty']);
        $job   = $this->job($label, null);

        $this->handler(serverIsEmpty: true)(new DeleteLabelMessage((int) $job->id));

        $next = $this->dispatched[0] ?? null;

        self::assertInstanceOf(Envelope::class, $next);
        self::assertNull($next->last(DelayStamp::class));
    }

    public function testOnceTheServerIsEmptyTheLabelGoesWithItsNestedLabels(): void
    {
        $root = $this->folderLabel(['Old']);
        $leaf = $this->folderLabel(['Old', 'Nested']);

        // Ids now: the handler removes these very objects, and Doctrine
        // forgets a removed entity's id.
        $rootId = $root->id;
        $leafId = $leaf->id;

        self::assertSame($rootId, $leaf->parent?->id);

        $job     = $this->job($root, null);
        $removed = new ArrayObject();

        $this->handler(serverIsEmpty: true, removed: $removed)(
            new DeleteLabelMessage((int) $job->id, DeleteLabelMessage::PHASE_REMOTE),
        );

        $this->em->clear();

        self::assertNull($this->em->find(Label::class, $rootId));
        self::assertNull($this->em->find(Label::class, $leafId));
        $done = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($done);
        self::assertSame(JobState::Done, $done->state, (string) $done->failureReason);

        // Children first: the server refuses a folder with folders in it.
        self::assertSame(['Old/Nested', 'Old'], $removed->getArrayCopy());
    }

    /**
     * A folder that still holds mail is asked about again, later — not
     * deleted, and not given up on.
     */
    public function testAFolderStillHoldingMailIsAskedAboutAgain(): void
    {
        $label = $this->folderLabel(['Busy']);
        $job   = $this->job($label, null);

        $this->handler(serverIsEmpty: false)(
            new DeleteLabelMessage((int) $job->id, DeleteLabelMessage::PHASE_REMOTE, 3),
        );

        $this->em->clear();

        self::assertNotNull($this->em->find(Label::class, $label->id));
        self::assertTrue($this->em->find(BackgroundJob::class, $job->id)?->isActive());

        $next = $this->dispatched[0] ?? null;

        self::assertInstanceOf(Envelope::class, $next);
        self::assertSame(4, $next->getMessage()->attempt);
        self::assertNotNull($next->last(DelayStamp::class));
    }

    /**
     * A folder that never empties ends the job as failed, saying which, and
     * leaves both the label and the folder: nothing is deleted to get there.
     */
    public function testAFolderThatNeverEmptiesFailsTheJobAndKeepsTheLabel(): void
    {
        $label = $this->folderLabel(['Stuck']);
        $job   = $this->job($label, null);

        $this->handler(serverIsEmpty: false)(
            new DeleteLabelMessage((int) $job->id, DeleteLabelMessage::PHASE_REMOTE, 39),
        );

        $this->em->clear();

        $fresh = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($fresh);
        self::assertSame(JobState::Failed, $fresh->state);
        self::assertStringContainsString('Stuck', (string) $fresh->failureReason);
        self::assertNotNull($this->em->find(Label::class, $label->id));
        self::assertCount(0, $this->dispatched);
    }

    /**
     * A server that says no to the delete is not asked again for ten minutes:
     * the job ends now, in the server's words, and the label stays.
     */
    public function testARefusedDeleteEndsTheJobAtOnce(): void
    {
        $label = $this->folderLabel(['Protected']);
        $job   = $this->job($label, null);

        $this->handler(serverIsEmpty: true, refuses: true)(
            new DeleteLabelMessage((int) $job->id, DeleteLabelMessage::PHASE_REMOTE),
        );

        $this->em->clear();

        $fresh = $this->em->find(BackgroundJob::class, $job->id);

        self::assertNotNull($fresh);
        self::assertSame(JobState::Failed, $fresh->state);
        self::assertStringContainsString('Protected', (string) $fresh->failureReason);
        self::assertStringContainsString('Permission denied', (string) $fresh->failureReason);
        self::assertNotNull($this->em->find(Label::class, $label->id));
        self::assertCount(0, $this->dispatched, 'a refusal was queued to be asked again');
    }

    /** While it waits, the job says for which folder — that is the indicator's line. */
    public function testAWaitingJobSaysWhichFolderItIsWaitingFor(): void
    {
        $label = $this->folderLabel(['Busy']);
        $job   = $this->job($label, null);

        $this->handler(serverIsEmpty: false)(
            new DeleteLabelMessage((int) $job->id, DeleteLabelMessage::PHASE_REMOTE),
        );

        $this->em->clear();

        self::assertSame('Busy', $this->em->find(BackgroundJob::class, $job->id)?->view['waitingOn'] ?? null);
    }

    /** Mail that arrived after the dialog said the folder was empty. */
    public function testMailWithNoChosenDestinationIsNotMovedAnywhere(): void
    {
        $label   = $this->folderLabel(['Late']);
        $message = $this->messageIn($label, 'Arrived later');
        $job     = $this->job($label, null);

        $this->handler(serverIsEmpty: true)(new DeleteLabelMessage((int) $job->id));

        $this->em->clear();

        self::assertSame(JobState::Failed, $this->em->find(BackgroundJob::class, $job->id)?->state);
        self::assertContains('Late', $this->labelNames($this->em->find(Message::class, $message->id)));
        self::assertNotNull($this->em->find(Label::class, $label->id));
    }

    private function job(Label $label, ?Label $destination): BackgroundJob
    {
        $job       = new BackgroundJob($this->user, JobKind::DeleteLabel);
        $job->view = [
            'scope'         => 'label',
            'value'         => (string) $label->id,
            'unreadOnly'    => false,
            'labelId'       => $label->id,
            'destinationId' => $destination?->id,
        ];

        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    /**
     * @param ArrayObject<int, string>|null $removed  full names, in the order
     *                                               the double was asked
     * @param ArrayObject<int, string>|null $prepared roles the double was
     *                                               asked to create a folder for
     */
    private function handler(
        bool         $serverIsEmpty,
        ?ArrayObject $removed = null,
        bool         $refuses = false,
        ?ArrayObject $prepared = null,
        bool         $canCreate = true,
    ): DeleteLabelHandler
    {
        $container  = self::getContainer();
        $dispatched = $this->dispatched;
        $removed  ??= new ArrayObject();
        $prepared ??= new ArrayObject();

        $remover = new readonly class ($serverIsEmpty, $removed, $refuses, $prepared, $canCreate) extends RemoteLabelRemover {
            /**
             * @param ArrayObject<int, string> $removed
             * @param ArrayObject<int, string> $prepared
             */
            public function __construct(
                private bool        $empty,
                private ArrayObject $removed,
                private bool        $refuses,
                private ArrayObject $prepared,
                private bool        $canCreate,
            ) {
            }

            public function prepareDestination(Account $account, LabelRole $role): bool
            {
                $this->prepared[] = $role->value;

                return $this->canCreate;
            }

            public function remove(LabelBinding $binding): bool
            {
                $this->removed[] = (string) $binding->label?->fullName;

                if (true === $this->refuses) {
                    throw new LabelDeleteRefusedException(
                        (string) $binding->label?->fullName,
                        new \RuntimeException('NO [NOPERM] Permission denied'),
                    );
                }

                return $this->empty;
            }
        };

        $bus = new class ($dispatched) implements MessageBusInterface {
            /** @param ArrayObject<int, object> $sent */
            public function __construct(private ArrayObject $sent)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope     = Envelope::wrap($message, $stamps);
                $this->sent[] = $envelope;

                return $envelope;
            }
        };

        return new DeleteLabelHandler(
            $container->get(\App\Repository\Job\BackgroundJobRepository::class),
            $container->get(\App\Repository\Label\LabelRepository::class),
            $container->get(\App\Repository\Mail\MessageRepository::class),
            $container->get(\App\Service\Label\LabelDeletion::class),
            $remover,
            $container->get(\App\Service\Mail\ThreadStatusUpdater::class),
            $container->get(\App\Service\Mail\MailChangeRecorder::class),
            $container->get(\App\Service\Label\LabelNotifier::class),
            $container->get(\App\Service\Job\JobNotifier::class),
            $bus,
            $container->get(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),
            $container->get(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->em,
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }

    /**
     * @return list<string>
     */
    private function labelNames(?Message $message): array
    {
        return array_values($message?->labels->map(static fn (Label $label): string => (string) $label->name)->toArray() ?? []);
    }
}
