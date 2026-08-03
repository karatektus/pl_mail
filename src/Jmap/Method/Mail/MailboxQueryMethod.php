<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Label\LabelBinding;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Mapper\MailboxMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelBindingRepository;

/**
 * "Mailbox/query" (RFC 8621 §2.3). Returns the ordered id list for the account.
 * Supports the two filter conditions clients actually use on mailboxes
 * (parentId, role); an absent filter returns all. canCalculateChanges is false
 * until Mailbox/queryChanges lands.
 */
final class MailboxQueryMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly LabelBindingRepository $bindingRepository,
        private readonly MailboxMapper $mapper,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Mailbox/query';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

        $bindings           = $this->bindingRepository->findForAccountOrdered($accountId);
        $bindingIdByLabelId = $this->bindingRepository->bindingIdsByLabelId($accountId);

        $filter = $arguments['filter'] ?? null;

        if (null !== $filter) {
            if (false === is_array($filter)) {
                throw new MethodException('invalidArguments', '"filter" must be an object.');
            }

            $bindings = $this->applyFilter($bindings, $filter, $bindingIdByLabelId);
        }

        $ids = [];

        foreach ($bindings as $binding) {
            $ids[] = (string) $binding->id;
        }

        $total = count($ids);
        $position = $this->resolvePosition($arguments['position'] ?? 0, $total);

        $limit = $arguments['limit'] ?? null;

        if (null !== $limit && true === is_int($limit) && $limit >= 0) {
            $ids = array_slice($ids, $position, $limit);
        } else {
            $ids = array_slice($ids, $position);
        }

        $result = [
            'accountId' => (string) $accountId,
            'queryState' => $this->stateManager->stateFor($accountId, JmapObjectType::Mailbox),
            'canCalculateChanges' => false,
            'position' => $position,
            'ids' => array_values($ids),
        ];

        if (true === ($arguments['calculateTotal'] ?? false)) {
            $result['total'] = $total;
        }

        return $result;
    }

    private function resolvePosition(mixed $position, int $total): int
    {
        if (false === is_int($position)) {
            return 0;
        }

        if ($position < 0) {
            return max(0, $total + $position);
        }

        return $position;
    }

    /**
     * @param list<LabelBinding>  $bindings
     * @param array<string,mixed> $filter
     * @param array<int,int>      $bindingIdByLabelId
     *
     * @return list<LabelBinding>
     */
    private function applyFilter(array $bindings, array $filter, array $bindingIdByLabelId): array
    {
        $result = $bindings;

        if (true === array_key_exists('parentId', $filter)) {
            $wanted = null;

            if (null !== $filter['parentId']) {
                $wanted = (string) $filter['parentId'];
            }

            $result = array_values(array_filter(
                $result,
                fn (LabelBinding $binding): bool => $this->mapper->parentId($binding->label, $bindingIdByLabelId) === $wanted,
            ));
        }

        if (true === array_key_exists('role', $filter)) {
            $wanted = $filter['role'];
            $result = array_values(array_filter(
                $result,
                fn (LabelBinding $binding): bool => $this->mapper->roleOf($binding->label) === $wanted,
            ));
        }

        return $result;
    }
}
