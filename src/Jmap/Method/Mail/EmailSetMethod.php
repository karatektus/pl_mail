<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Mail\EmailPatchApplier;
use App\Jmap\Mail\JmapDraftWriter;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Email/set" (RFC 8621 §4.6): create drafts, update keywords/mailboxIds,
 * destroy.
 *
 * The keyword/mailbox patching lives in EmailPatchApplier, shared with
 * EmailSubmission/set. Everything it touches goes through
 * LabelChangePropagator, so a change made by a JMAP client reaches
 * Gmail/IMAP/Graph exactly as one made in the browser.
 *
 * destroy is a move to Trash rather than a row delete. plMail has no
 * hard-delete path — the UI's trash action and the propagator's delete() both
 * mean "TRASH" — so removing the row would discard the local copy of mail the
 * provider still holds.
 */
final class EmailSetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly LabelRepository $labelRepository,
        private readonly LabelResolver $labelResolver,
        private readonly MailboxRepository $mailboxRepository,
        private readonly LabelChangePropagator $propagator,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly EmailPatchApplier $patchApplier,
        private readonly JmapDraftWriter $draftWriter,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Email/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $oldState = $this->stateManager->stateFor($accountId, JmapObjectType::Email);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The account has changed since ifInState was issued.');
        }

        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];
        $destroyed = [];
        $notDestroyed = [];

        $this->applyCreates($account, $arguments['create'] ?? null, $context, $created, $notCreated);
        $this->applyUpdates($account, $arguments['update'] ?? null, $updated, $notUpdated);
        $this->applyDestroys($account, $arguments['destroy'] ?? null, $destroyed, $notDestroyed);

        $this->entityManager->flush();

        return [
            'accountId' => (string) $accountId,
            'oldState' => $oldState,
            'newState' => $this->stateManager->stateFor($accountId, JmapObjectType::Email),
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => array_values($destroyed),
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $created
     * @param array<string,mixed> $notCreated
     */
    private function applyCreates(
        Account $account,
        mixed $create,
        JmapContext $context,
        array &$created,
        array &$notCreated,
    ): void {
        if (null === $create) {
            return;
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        foreach ($create as $creationId => $properties) {
            $creationId = (string) $creationId;

            if (false === is_array($properties)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];
                continue;
            }

            try {
                $message = $this->draftWriter->create($account, $properties);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();
                continue;
            }

            $id = (string) $message->getId();

            $this->stateManager->recordCreated($account->getId(), JmapObjectType::Email, $id);
            // Lets a later call in the same request refer to "#creationId".
            $context->recordCreatedId($creationId, $id);

            // Server-set properties the client could not know; the spec
            // requires them back on the created object.
            $created[$creationId] = [
                'id' => $id,
                'blobId' => (string) BlobId::forMessage((int) $message->getId()),
                'threadId' => null === $message->getThread() ? null : (string) $message->getThread()->getId(),
                'size' => $message->getSize() ?? 0,
            ];
        }
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(Account $account, mixed $update, array &$updated, array &$notUpdated): void
    {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];
                continue;
            }

            $message = $this->findOne($account, $id);

            if (null === $message) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such Email in this account.'];
                continue;
            }

            try {
                $this->patchApplier->apply($account, $message, $patch);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            $this->stateManager->recordUpdated($account->getId(), JmapObjectType::Email, $id);
            $this->recordThread($account, $message);
            // null = "no properties changed beyond what the client asked for".
            $updated[$id] = null;
        }
    }

    /**
     * @param list<string>        $destroyed
     * @param array<string,mixed> $notDestroyed
     */
    private function applyDestroys(Account $account, mixed $destroy, array &$destroyed, array &$notDestroyed): void
    {
        if (null === $destroy) {
            return;
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        $trashLabel = $this->labelResolver->systemLabel(LabelRole::Trash, $account);
        $inboxLabel = $this->labelRepository->findOneByRoleForUser(LabelRole::Inbox, $account->getUsr());
        $trashMailbox = $trashLabel->bindingFor($account)?->mailbox;

        foreach ($destroy as $id) {
            $id = (string) $id;
            $message = $this->findOne($account, $id);

            if (null === $message) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such Email in this account.'];
                continue;
            }

            // Same order as ThreadStatusController::trash(): propagate first,
            // so the IMAP job still sees the source folder.
            $this->propagator->trash([$message]);

            $message->addLabel($trashLabel);

            if (null !== $inboxLabel) {
                $message->removeLabel($inboxLabel);
            }

            if (null !== $message->getImapUid() && null !== $trashMailbox) {
                $message->setMailbox($trashMailbox);
            }

            $this->threadLabelSynchronizer->sync($message->getThread());
            $this->stateManager->recordUpdated($account->getId(), JmapObjectType::Email, $id);
            $this->recordThread($account, $message);

            $destroyed[] = $id;
        }
    }

    /**
     * An Email mutation is also a Thread mutation — Thread/changes reads the
     * same log.
     */
    private function recordThread(Account $account, Message $message): void
    {
        $thread = $message->getThread();

        if (null === $thread) {
            return;
        }

        $this->stateManager->recordThreadsTouched((int) $account->getId(), [(int) $thread->getId()]);
    }

    private function findOne(Account $account, string $id): ?Message
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        $messages = $this->messageRepository->findByAccountAndIds($account->getId(), [(int) $id]);

        return $messages[0] ?? null;
    }
}
