<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\UnifiedInbox;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Service\Mail\UnifiedInboxes;

/**
 * "UnifiedInbox/get" — plMail extension, `urn:plmail:params:jmap:unifiedinboxes`.
 *
 * The reader's unified inboxes (#265) in sidebar order. Each one:
 *
 *   id          the inbox's id, a string like every JMAP id
 *   name        what the reader called it, or null for the client's own word —
 *               "Inbox", or for Other a word like "Other"; the web shows the
 *               reader's language in the same place
 *   icon        a name from the capability's `icons` list (Font Awesome's)
 *   sortOrder   0, 1, 2 … in the reader's order
 *   isOther     the catch-all: accounts the reader took out of their own
 *               inboxes. The web leaves it out of the sidebar while none of
 *               its accounts is switched on
 *   accountIds  the JMAP accounts whose Inbox it lists. A LIST although an
 *               account is in one inbox today, so that several later is no
 *               change of format. An account in no list does not exist
 *
 * Every account of a user answers with the same inboxes; the accountId says
 * whose change log the state belongs to, as for InboxTab/get. Read through
 * UnifiedInboxes, the class the web's sidebar is drawn from.
 */
final class UnifiedInboxGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly UnifiedInboxes $inboxes,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'UnifiedInbox/get';
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

        // Read before the rows, like every /get: a change landing between the
        // two is then reported again rather than missed.
        $state = $this->stateManager->stateFor($account->id, JmapObjectType::UnifiedInbox);

        $all = [];

        foreach ($this->inboxes->all($context->user) as $position => $inbox) {
            $all[(string) $inbox->id] = $this->toJmap($context, $inbox, $position);
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
            'state'     => $state,
            'list'      => $list,
            'notFound'  => $notFound,
        ];
    }

    /** @return array{id: string, name: string|null, icon: string, sortOrder: int, isOther: bool, accountIds: list<string>} */
    private function toJmap(JmapContext $context, UnifiedInbox $inbox, int $position): array
    {
        return [
            'id'         => (string) $inbox->id,
            'name'       => $inbox->name,
            'icon'       => $inbox->iconCase()->value,
            'sortOrder'  => $position,
            'isOther'    => $inbox->isOther,
            'accountIds' => array_map('strval', $this->inboxes->accountIdsOf($context->user, $inbox)),
        ];
    }
}
