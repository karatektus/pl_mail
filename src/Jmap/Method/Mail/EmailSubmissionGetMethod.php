<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;

/**
 * "EmailSubmission/get" (RFC 8621 §7.2).
 *
 * Reconstructed from the Message rather than stored: a submission id is the
 * Email id, and sentAt tells us whether the queued send has completed. Only
 * Emails that have actually been submitted resolve — an id that names a draft
 * that was never sent comes back in notFound, which is what a client polling
 * undoStatus needs to see.
 */
final class EmailSubmissionGetMethod implements JmapMethod
{
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'EmailSubmission/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

        $requestedIds = $arguments['ids'] ?? null;

        if (null === $requestedIds) {
            throw new MethodException('requestTooLarge', '"ids" is required for EmailSubmission/get.');
        }

        if (false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($requestedIds) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per EmailSubmission/get.', self::MAX_OBJECTS));
        }

        $requestedIds = array_values(array_map(
            static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
            $requestedIds,
        ));

        $messages = $this->messageRepository->findByAccountAndIds(
            $accountId,
            array_map('intval', $requestedIds),
        );

        $list = [];
        $found = [];

        foreach ($messages as $message) {
            if (null === $message->sentAt) {
                continue;
            }

            $found[] = (string) $message->id;
            $list[] = $this->toJmap($message, (string) $accountId);
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::EmailSubmission),
            'list' => $list,
            'notFound' => array_values(array_diff($requestedIds, $found)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toJmap(Message $message, string $accountId): array
    {
        $id = (string) $message->id;

        return [
            'id' => $id,
            'identityId' => $accountId,
            'emailId' => $id,
            'threadId' => null === $message->thread ? null : (string) $message->thread->id,
            'envelope' => [
                'mailFrom' => ['email' => (string) $message->fromAddress, 'parameters' => null],
                'rcptTo' => $this->recipients($message),
            ],
            'sendAt' => $message->sentAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'undoStatus' => 'final',
            'deliveryStatus' => null,
            'dsnBlobIds' => [],
            'mdnBlobIds' => [],
        ];
    }

    /**
     * @return list<array{email:string,parameters:null}>
     */
    private function recipients(Message $message): array
    {
        $recipients = [];

        foreach ([$message->toAddresses, $message->ccAddresses, $message->bccAddresses] as $group) {
            if (false === is_array($group)) {
                continue;
            }

            foreach ($group as $entry) {
                if (false === is_array($entry)) {
                    continue;
                }

                $address = $entry['address'] ?? null;

                if (true === is_string($address) && '' !== $address) {
                    $recipients[] = ['email' => $address, 'parameters' => null];
                }
            }
        }

        return $recipients;
    }
}
