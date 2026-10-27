<?php

declare(strict_types=1);

namespace App\Jmap\Method\ContactCard;

use App\Jmap\Account\ContactAccountResolver;
use App\Jmap\Contact\ContactCards;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Contacts\Change\ContactChangeReader;

/**
 * "AddressBook/get" (RFC 9610 §2.1): plMail's own book and every mirrored one.
 * See ContactCards for what a book is here.
 */
final class AddressBookGetMethod implements JmapMethod
{
    public function __construct(
        private readonly ContactAccountResolver $accounts,
        private readonly ContactCards $cards,
        private readonly ContactChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'AddressBook/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $ids     = $arguments['ids'] ?? null;

        if (null !== $ids && false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        $books    = $this->cards->books($context->user);
        $list     = [];
        $notFound = [];

        if (null === $ids) {
            $list = $books;
        } else {
            $byId = array_column($books, null, 'id');

            foreach ($ids as $id) {
                $id = (string) $id;

                if (true === isset($byId[$id])) {
                    $list[] = $byId[$id];
                } else {
                    $notFound[] = $id;
                }
            }
        }

        return [
            'accountId' => (string) $account->id,
            'state'     => $this->changes->bookState((int) $context->user->id),
            'list'      => $list,
            'notFound'  => $notFound,
        ];
    }
}
