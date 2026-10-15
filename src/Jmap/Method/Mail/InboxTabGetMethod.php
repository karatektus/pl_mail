<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Mapper\InboxTabMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Service\Mail\InboxTabs;

/**
 * "InboxTab/get" — plMail extension, `urn:plmail:params:jmap:inboxtabs`.
 *
 * The reader's inbox tabs in the order they arranged them, switched-off
 * built-ins included and marked — see InboxTabMapper for the object. Every
 * account of a user answers with the same list; the accountId only says whose
 * change log the state token belongs to.
 *
 * Read through InboxTabs, the class the web strip is drawn from, so a phone and
 * the browser cannot disagree about which tabs exist or where they go.
 */
final class InboxTabGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly InboxTabs $tabs,
        private readonly InboxTabMapper $mapper,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'InboxTab/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $properties = $arguments['properties'] ?? null;

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $ids = $arguments['ids'] ?? null;

        if (null !== $ids && false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        $all = [];

        foreach ($this->tabs->managedFor($context->user) as $position => $tab) {
            $all[$tab->value] = $this->mapper->toJmap($tab, $position, $context->user);
        }

        $list     = [];
        $notFound = [];

        foreach (null === $ids ? array_keys($all) : array_map('strval', $ids) as $id) {
            if (false === isset($all[$id])) {
                $notFound[] = $id;

                continue;
            }

            $object = $all[$id];

            if (null !== $properties) {
                // "id" always comes back, whatever was asked for (RFC 8620 §5.1).
                $object = array_intersect_key($object, array_flip([...array_map('strval', $properties), 'id']));
            }

            $list[] = $object;
        }

        return [
            'accountId' => (string) $account->id,
            'state'     => $this->stateManager->stateFor($account->id, JmapObjectType::InboxTab),
            'list'      => $list,
            'notFound'  => $notFound,
        ];
    }
}
