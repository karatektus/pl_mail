<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Mapper\MailboxCountsProvider;
use App\Jmap\Mapper\MailboxMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\LabelBindingRepository;

/**
 * "Mailbox/get" (RFC 8621 §2.1). ids = null returns every mailbox for the
 * account; otherwise the named ids, with any missing ones listed in notFound.
 */
final class MailboxGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly LabelBindingRepository $bindingRepository,
        private readonly MailboxMapper $mapper,
        private readonly MailboxCountsProvider $countsProvider,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Mailbox/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $properties = $arguments['properties'] ?? null;

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $requestedIds = $arguments['ids'] ?? null;
        $notFound = [];

        if (null === $requestedIds) {
            $bindings = $this->bindingRepository->findForAccountOrdered($accountId);
        } else {
            if (false === is_array($requestedIds)) {
                throw new MethodException('invalidArguments', '"ids" must be an array or null.');
            }

            $requestedIds = array_values(array_map('strval', $requestedIds));
            $intIds = array_map('intval', $requestedIds);
            $bindings = $this->bindingRepository->findForAccountAndIds($accountId, $intIds);

            $found = [];

            foreach ($bindings as $binding) {
                $found[] = (string) $binding->id;
            }

            $notFound = array_values(array_diff($requestedIds, $found));
        }

        $counts = $this->countsProvider->forAccount($accountId);
        // parentId is expressed in binding ids, and a requested subset may not
        // contain the parents — so the map always covers the whole account.
        $bindingIdByLabelId = $this->bindingRepository->bindingIdsByLabelId($accountId);
        $list = [];

        foreach ($bindings as $binding) {
            $list[] = $this->mapper->toJmapWithProperties($binding, $counts, $properties, $bindingIdByLabelId);
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::Mailbox),
            'list' => $list,
            'notFound' => $notFound,
        ];
    }
}
