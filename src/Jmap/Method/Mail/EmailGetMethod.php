<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Mapper\EmailMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\MessageRepository;

/**
 * "Email/get" (RFC 8621 §4.2).
 *
 * Unlike Mailbox/get, ids is REQUIRED: an account's full mail is unbounded, so
 * the spec has clients reach Emails through Email/query. A null ids is
 * therefore requestTooLarge rather than "return everything".
 */
final class EmailGetMethod implements JmapMethod
{
    /**
     * Ceiling on ids per call, matching maxObjectsInGet in the Session object.
     */
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly EmailMapper $mapper,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Email/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $requestedIds = $arguments['ids'] ?? null;

        if (null === $requestedIds) {
            throw new MethodException('requestTooLarge', '"ids" is required for Email/get; use Email/query to select them.');
        }

        if (false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($requestedIds) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per Email/get.', self::MAX_OBJECTS));
        }

        $properties = $arguments['properties'] ?? null;

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $bodyProperties = $arguments['bodyProperties'] ?? null;

        if (null !== $bodyProperties && false === is_array($bodyProperties)) {
            throw new MethodException('invalidArguments', '"bodyProperties" must be an array or null.');
        }

        // "#creationId" entries refer to Emails created earlier in this same
        // request; an unknown one stays as-is and falls out as notFound.
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
            $found[] = (string) $message->getId();
            $list[] = $this->mapper->toJmap(
                $message,
                $properties,
                true === ($arguments['fetchTextBodyValues'] ?? false),
                true === ($arguments['fetchHTMLBodyValues'] ?? false),
                $bodyProperties,
            );
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::Email),
            'list' => $list,
            'notFound' => array_values(array_diff($requestedIds, $found)),
        ];
    }
}
