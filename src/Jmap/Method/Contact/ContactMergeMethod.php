<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactMerger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Contact/merge": folds one or more contacts into a kept contact and records
 * the merge so Contact/mergeUndo can put it back.
 *
 * Every id is verified up front: an unknown or somebody else's id is notFound
 * rather than forbidden, and merging a contact into itself is refused as
 * invalidArguments before the merger ever sees it.
 */
final class ContactMergeMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'keepId', 'mergeIds'];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactRepository $contacts,
        private readonly ContactMerger $merger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Contact/merge';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $keepId = $this->requireId($arguments['keepId'] ?? null, 'keepId');
        $mergeIds = $this->requireIdList($arguments['mergeIds'] ?? null);

        $this->assertOwned($context, $keepId, 'keepId');

        $seen = [];

        foreach ($mergeIds as $mergeId) {
            if ($mergeId === $keepId) {
                throw new MethodException('invalidArguments', 'A contact cannot be merged into itself.');
            }

            if (true === in_array($mergeId, $seen, true)) {
                throw new MethodException('invalidArguments', sprintf('Duplicate merge id "%s".', $mergeId));
            }

            $seen[] = $mergeId;
            $this->assertOwned($context, $mergeId, 'mergeIds');
        }

        try {
            $record = $this->merger->merge(
                (int) $keepId,
                array_map(static fn (string $id): int => (int) $id, $mergeIds),
                $context->user,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new MethodException('invalidArguments', $exception->getMessage());
        }

        $this->entityManager->flush();

        return [
            'accountId' => (string) $account->id,
            'mergeRecordId' => (string) $record->id,
        ];
    }

    private function requireId(mixed $id, string $field): string
    {
        if (false === is_string($id) && false === is_int($id)) {
            throw new MethodException('invalidArguments', sprintf('A string "%s" is required.', $field));
        }

        $id = (string) $id;

        if (false === ctype_digit($id)) {
            throw new MethodException('invalidArguments', sprintf('"%s" must be a contact id.', $field));
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private function requireIdList(mixed $ids): array
    {
        if (false === is_array($ids) || [] === $ids) {
            throw new MethodException('invalidArguments', '"mergeIds" is required and must be a non-empty array of contact id strings.');
        }

        $result = [];

        foreach (array_values($ids) as $id) {
            $result[] = $this->requireId($id, 'mergeIds');
        }

        return $result;
    }

    /**
     * Somebody else's contact reads as notFound, never forbidden — the two
     * are distinguishable to a client, and only one of them confirms that
     * the id exists.
     */
    private function assertOwned(JmapContext $context, string $id, string $field): void
    {
        $contact = $this->contacts->findOneBy(['id' => (int) $id, 'usr' => $context->user]);

        if (null === $contact) {
            throw new MethodException('notFound', sprintf('No such contact for "%s".', $field));
        }
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
                '"%s" is not an argument of Contact/merge. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
