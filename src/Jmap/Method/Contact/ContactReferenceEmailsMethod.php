<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ReferenceEmailFinder;

/**
 * "Contact/referenceEmails": every non-deleted message to or from one of the
 * contact's addresses, newest first — the same list the web contact page
 * renders as its REFERENCE EMAILS section.
 */
final class ContactReferenceEmailsMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'contactId', 'limit'];

    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactRepository $contacts,
        private readonly ReferenceEmailFinder $finder,
    ) {
    }

    public function name(): string
    {
        return 'Contact/referenceEmails';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $contactId = $arguments['contactId'] ?? null;

        if (false === is_string($contactId) && false === is_int($contactId)) {
            throw new MethodException('invalidArguments', 'A string "contactId" is required.');
        }

        $contactId = (string) $contactId;
        $limit = $this->resolveLimit($arguments['limit'] ?? null);

        $contact = true === ctype_digit($contactId)
            ? $this->contacts->findOneBy(['id' => (int) $contactId, 'usr' => $context->user])
            : null;

        if (null === $contact) {
            throw new MethodException('notFound', 'No such contact.');
        }

        $list = [];

        foreach ($this->finder->findForContact($context->user, $contact, $limit) as $row) {
            $list[] = [
                'id' => (string) $row['id'],
                'subject' => $row['subject'],
                'from' => [
                    'name' => $row['fromName'],
                    'email' => $row['fromAddress'],
                ],
                'date' => $row['receivedAt'],
                'mailbox' => $row['mailbox'],
                'isStarred' => $row['isStarred'],
                'hasAttachment' => $row['hasAttachment'],
            ];
        }

        return [
            'accountId' => (string) $account->id,
            'contactId' => $contactId,
            'list' => $list,
        ];
    }

    private function resolveLimit(mixed $limit): int
    {
        if (null === $limit) {
            return self::DEFAULT_LIMIT;
        }

        if (false === is_int($limit) || $limit < 1) {
            throw new MethodException('invalidArguments', sprintf(
                '"limit" must be an integer of at least 1, or null for %d.',
                self::DEFAULT_LIMIT,
            ));
        }

        return min($limit, self::MAX_LIMIT);
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
                '"%s" is not an argument of Contact/referenceEmails. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
