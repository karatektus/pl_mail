<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Contacts\DuplicateFinder;

/**
 * "Contact/duplicates": the candidate duplicate pairs DuplicateFinder sees
 * in the user's address book, for the client to offer a keep-picker on.
 *
 * Read-only — merging is Contact/merge's job, and the finder decides what
 * "autoMergeable" means, not this method.
 */
final class ContactDuplicatesMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId'];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly DuplicateFinder $finder,
    ) {
    }

    public function name(): string
    {
        return 'Contact/duplicates';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $pairs = [];

        foreach ($this->finder->findCandidates($context->user) as $pair) {
            $pairs[] = [
                'ids' => [(string) $pair->a->id, (string) $pair->b->id],
                'confidence' => $pair->confidence,
                'reasons' => array_values($pair->reasons),
                'autoMergeable' => $pair->autoMergeable,
            ];
        }

        return [
            'accountId' => (string) $account->id,
            'pairs' => $pairs,
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function rejectUnknownArguments(array $arguments): void
    {
        foreach (array_keys($arguments) as $argument) {
            if (true === in_array((string) $argument, self::ACCEPTED_ARGUMENTS, true)) {
                continue;
            }

            throw new MethodException('invalidArguments', sprintf(
                '"%s" is not an argument of Contact/duplicates. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
