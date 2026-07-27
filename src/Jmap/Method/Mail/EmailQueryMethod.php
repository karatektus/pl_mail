<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Query\EmailQueryRunner;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * "Email/query" (RFC 8621 §4.4). Returns the ids of the Emails matching a
 * filter, in sort order, windowed by position/limit. Clients pair it with an
 * Email/get on "#ids" in the same request.
 */
final class EmailQueryMethod implements JmapMethod
{
    private const int MAX_LIMIT = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly EmailQueryRunner $runner,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Email/query';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $filter = $arguments['filter'] ?? null;

        if (null !== $filter && false === is_array($filter)) {
            throw new MethodException('invalidArguments', '"filter" must be an object or null.');
        }

        $sort = $arguments['sort'] ?? null;

        if (null !== $sort && false === is_array($sort)) {
            throw new MethodException('invalidArguments', '"sort" must be an array or null.');
        }

        $position = $arguments['position'] ?? 0;

        if (false === is_int($position) || $position < 0) {
            // Negative positions (anchor from the end) are not implemented.
            throw new MethodException('invalidArguments', '"position" must be a non-negative integer.');
        }

        $limit = $arguments['limit'] ?? null;

        if (null !== $limit && (false === is_int($limit) || $limit < 0)) {
            throw new MethodException('invalidArguments', '"limit" must be a non-negative integer or null.');
        }

        if (null === $limit || $limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        $collapseThreads = $arguments['collapseThreads'] ?? false;

        if (false === is_bool($collapseThreads)) {
            throw new MethodException('invalidArguments', '"collapseThreads" must be a boolean.');
        }

        $anchor = $arguments['anchor'] ?? null;

        if (null !== $anchor) {
            throw new MethodException('unsupportedFilter', 'Anchor-based paging is not supported; use "position".');
        }

        $result = $this->runner->run($accountId, $filter, $sort, $collapseThreads, $position, $limit);

        return [
            'accountId' => (string) $accountId,
            'queryState' => $this->stateManager->stateFor($accountId, JmapObjectType::Email),
            // No Email/queryChanges yet, so clients must re-run the query
            // rather than ask for a delta against queryState.
            'canCalculateChanges' => false,
            'position' => $result->position,
            'ids' => $result->ids,
            'total' => $result->total,
            'limit' => $limit,
        ];
    }
}
