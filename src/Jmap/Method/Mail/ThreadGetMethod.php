<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageThreadRepository;

/**
 * "Thread/get" (RFC 8621 §3.1). A Thread is just an id plus its Emails in
 * receivedAt order, which is exactly how MessageThread::$messages is mapped
 * (#[ORM\OrderBy] on receivedAt then id), so no re-sorting is needed here.
 */
final class ThreadGetMethod implements JmapMethod
{
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageThreadRepository $threadRepository,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Thread/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $requestedIds = $arguments['ids'] ?? null;

        if (null === $requestedIds) {
            throw new MethodException('requestTooLarge', '"ids" is required for Thread/get.');
        }

        if (false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($requestedIds) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per Thread/get.', self::MAX_OBJECTS));
        }

        $requestedIds = array_values(array_map(
            static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
            $requestedIds,
        ));

        $threads = $this->threadRepository->findByAccountAndIds(
            $accountId,
            array_map('intval', $requestedIds),
        );

        $list = [];
        $found = [];

        foreach ($threads as $thread) {
            $found[] = (string) $thread->getId();

            $emailIds = [];

            foreach ($thread->getMessages() as $message) {
                $emailIds[] = (string) $message->getId();
            }

            $list[] = [
                'id' => (string) $thread->getId(),
                'emailIds' => $emailIds,
                // plMail extension. RFC 8621's Thread has only id and emailIds;
                // this is the one piece of thread state the product owns, and
                // the web UI already acts on it. A client that computed snooze
                // locally instead would disagree with the browser and lose it
                // on reinstall, which is exactly the case the client guide
                // names as ask-don't-work-around.
                //
                // Null when never snoozed *and* when the snooze has already
                // expired — a past timestamp is not a state anything should
                // render, and leaving it would have every client re-implement
                // the comparison.
                'snoozedUntil' => $this->utcOrNull(
                    $thread->isSnoozed() ? $thread->getSnoozedUntil() : null,
                ),
            ];
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::Thread),
            'list' => $list,
            'notFound' => array_values(array_diff($requestedIds, $found)),
        ];
    }

    /**
     * The same shape EmailMapper::utcOrNull emits, and for the same reason:
     * clients parse this format exactly, and a value left in the server's
     * local zone would be read as UTC and land hours out.
     */
    private function utcOrNull(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
