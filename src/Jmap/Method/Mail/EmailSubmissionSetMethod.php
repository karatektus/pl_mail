<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Mail\EmailPatchApplier;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "EmailSubmission/set" (RFC 8621 §7.5) — sending.
 *
 * Sending is delegated to the existing SendMessageMessage / SendMessageHandler
 * / MessageSendService pipeline, the same one the web composer's send button
 * uses. That service already performs the draft->sent transition (adds Sent,
 * removes Drafts, clears the \Draft flag, sets sentAt, re-points the mailbox),
 * so a client that omits onSuccessUpdateEmail still ends up correct.
 *
 * A submission has no table of its own: its id IS the Email id. That is enough
 * to satisfy the object model here because plMail sends each draft at most
 * once (MessageSendService is a no-op once sentAt is set), so the mapping
 * stays one-to-one and EmailSubmission/get can reconstruct from the Message.
 *
 * undoStatus is reported as "pending": the send is queued on the messenger bus
 * and has genuinely not happened yet when this returns. Note the web composer's
 * undo window is deliberately NOT applied — a JMAP client asked to send now.
 */
final class EmailSubmissionSetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly EmailPatchApplier $patchApplier,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function name(): string
    {
        return 'EmailSubmission/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $oldState = $this->stateManager->stateFor($accountId, JmapObjectType::EmailSubmission);

        $created = [];
        $notCreated = [];

        /** @var array<string,Message> $sent creationId => the Email that was submitted */
        $sent = [];

        $create = $arguments['create'] ?? null;

        if (null !== $create) {
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
                    $message = $this->submit($account, $properties, $context);
                } catch (MethodException $exception) {
                    $notCreated[$creationId] = $exception->toError();
                    continue;
                }

                $id = (string) $message->id;

                $this->stateManager->recordCreated($accountId, JmapObjectType::EmailSubmission, $id);
                $context->recordCreatedId($creationId, $id);
                $sent[$creationId] = $message;

                $created[$creationId] = [
                    'id' => $id,
                    'sendAt' => (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                    'undoStatus' => 'pending',
                ];
            }
        }

        $updatedEmails = $this->applyOnSuccess($account, $arguments, $sent);

        $this->entityManager->flush();

        $result = [
            'accountId' => (string) $accountId,
            'oldState' => $oldState,
            'newState' => $this->stateManager->stateFor($accountId, JmapObjectType::EmailSubmission),
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => new \stdClass(),
            'notUpdated' => new \stdClass(),
            'destroyed' => [],
            'notDestroyed' => new \stdClass(),
        ];

        if (count($updatedEmails) > 0) {
            // The spec has the server report the implicit Email/set it just
            // performed, so the client does not have to re-fetch.
            $result['updatedEmails'] = $updatedEmails;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $properties
     */
    private function submit(Account $account, array $properties, JmapContext $context): Message
    {
        $emailId = $properties['emailId'] ?? null;

        if (false === is_string($emailId) || '' === $emailId) {
            throw new MethodException('invalidProperties', 'An "emailId" is required.');
        }

        $resolved = $context->resolveId($emailId);

        if (null === $resolved) {
            throw new MethodException('invalidProperties', sprintf('Unknown creation id "%s".', $emailId));
        }

        $message = $this->findOne($account, $resolved);

        if (null === $message) {
            throw new MethodException('invalidProperties', 'No such Email in this account.');
        }

        if (null !== $message->sentAt) {
            throw new MethodException('alreadyExists', 'That Email has already been submitted.');
        }

        if (null === $message->toAddresses && null === $message->ccAddresses && null === $message->bccAddresses) {
            throw new MethodException('noRecipients', 'The Email has no recipients.');
        }

        $this->bus->dispatch(new SendMessageMessage((int) $message->id));

        return $message;
    }

    /**
     * onSuccessUpdateEmail patches are keyed by "#creationId" of the
     * submission they are conditional on, and only run for submissions that
     * actually succeeded.
     *
     * @param array<string,mixed>   $arguments
     * @param array<string,Message> $sent
     *
     * @return array<string,mixed>
     */
    private function applyOnSuccess(Account $account, array $arguments, array $sent): array
    {
        $onSuccess = $arguments['onSuccessUpdateEmail'] ?? null;

        if (false === is_array($onSuccess) || 0 === count($sent)) {
            return [];
        }

        $updatedEmails = [];

        foreach ($onSuccess as $reference => $patch) {
            $creationId = ltrim((string) $reference, '#');
            $message = $sent[$creationId] ?? null;

            if (null === $message || false === is_array($patch)) {
                continue;
            }

            $this->patchApplier->apply($account, $message, $patch);
            $this->stateManager->recordUpdated($account->getId(), JmapObjectType::Email, (string) $message->id);

            $updatedEmails[(string) $message->id] = null;
        }

        return $updatedEmails;
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
