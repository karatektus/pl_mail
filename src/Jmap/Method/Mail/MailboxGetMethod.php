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
use App\Repository\LabelRepository;

/**
 * "Mailbox/get" (RFC 8621 §2.1). ids = null returns every mailbox for the
 * account; otherwise the named ids, with any missing ones listed in notFound.
 */
final class MailboxGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly LabelRepository $labelRepository,
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
            $labels = $this->labelRepository->findByAccountOrdered($accountId);
        } else {
            if (false === is_array($requestedIds)) {
                throw new MethodException('invalidArguments', '"ids" must be an array or null.');
            }

            $requestedIds = array_values(array_map('strval', $requestedIds));
            $intIds = array_map('intval', $requestedIds);
            $labels = $this->labelRepository->findByAccountAndIds($accountId, $intIds);

            $found = [];

            foreach ($labels as $label) {
                $found[] = (string) $label->id;
            }

            $notFound = array_values(array_diff($requestedIds, $found));
        }

        $counts = $this->countsProvider->forAccount($accountId);
        $list = [];

        foreach ($labels as $label) {
            $list[] = $this->mapper->toJmapWithProperties($label, $counts, $properties);
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::Mailbox),
            'list' => $list,
            'notFound' => $notFound,
        ];
    }
}
