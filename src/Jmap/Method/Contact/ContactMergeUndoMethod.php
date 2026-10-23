<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Entity\Contacts\ContactMergeRecord;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Contacts\ContactMerger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Contact/mergeUndo": restores the contacts a Contact/merge folded away,
 * from the snapshot the merge recorded.
 *
 * The undo window is 90 days from the merge. A missing record — or one that
 * belongs to somebody else — is notFound; an expired one is invalidArguments
 * with the reason spelled out, because "no such merge" and "too late to
 * undo it" are different facts and a client can act on only one of them.
 */
final class ContactMergeUndoMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'mergeRecordId'];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactMerger $merger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Contact/mergeUndo';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $recordId = $arguments['mergeRecordId'] ?? null;

        if ((false === is_string($recordId) && false === is_int($recordId)) || false === ctype_digit((string) $recordId)) {
            throw new MethodException('invalidArguments', 'A string "mergeRecordId" is required.');
        }

        $record = $this->entityManager->find(ContactMergeRecord::class, (int) $recordId);

        if (null === $record || $record->usr->id !== $context->user->id) {
            throw new MethodException('notFound', 'No such merge record.');
        }

        if (new DateTimeImmutable() > $record->undoUntil) {
            throw new MethodException(
                'invalidArguments',
                'The undo window for this merge has expired; merges can be undone for 90 days.',
            );
        }

        try {
            $this->merger->undo((int) $record->id, $context->user);
        } catch (\RuntimeException $exception) {
            // The window check above should have caught this; the merger is
            // the authority on its own clock, so its refusal wins.
            throw new MethodException('invalidArguments', 'The undo window for this merge has expired; merges can be undone for 90 days.');
        } catch (\InvalidArgumentException $exception) {
            throw new MethodException('invalidArguments', $exception->getMessage());
        }

        $this->entityManager->flush();

        return [
            'accountId' => (string) $account->id,
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
                '"%s" is not an argument of Contact/mergeUndo. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
