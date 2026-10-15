<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Mail\EmailImporter;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * "Email/import" (RFC 8621 §4.8): Emails from uploaded RFC 5322 messages.
 *
 * Arguments: accountId, ifInState, emails — creation id → {blobId,
 * mailboxIds, keywords, receivedAt}, plus the plMail argument `fingerprints`
 * (and `signer`) for an encrypted `$draft` that is to be sent
 * (`urn:plmail:params:jmap:keys`). See EmailImporter for what is made of each.
 *
 * Answers created (id, blobId, threadId, size) and notCreated, with the
 * SetErrors the RFC names: blobNotFound, invalidProperties — and forbidden
 * for an encrypted draft not encrypted to its recipients' current keys.
 */
final class EmailImportMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accounts,
        private readonly EmailImporter   $importer,
        private readonly StateManager    $states,
    ) {
    }

    public function name(): string
    {
        return 'Email/import';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account  = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $oldState = $this->states->stateFor($account->id, JmapObjectType::Email);
        $emails   = $arguments['emails'] ?? null;

        if (null !== ($arguments['ifInState'] ?? null) && $arguments['ifInState'] !== $oldState) {
            throw new MethodException('stateMismatch', 'The Email state has moved on.');
        }

        if (false === is_array($emails)) {
            throw new MethodException('invalidArguments', '"emails" must map creation ids to EmailImport objects.');
        }

        $created    = [];
        $notCreated = [];

        foreach ($emails as $creationId => $import) {
            $creationId = (string) $creationId;

            if (false === is_array($import)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each import must be an object.'];

                continue;
            }

            try {
                $message = $this->importer->import($account, $import);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();

                continue;
            }

            $id = (string) $message->id;

            $this->states->recordCreated($account->id, JmapObjectType::Email, $id);
            $context->recordCreatedId($creationId, $id);

            $created[$creationId] = [
                'id'       => $id,
                'blobId'   => (string) BlobId::forMessage((int) $message->id),
                'threadId' => null === $message->thread ? null : (string) $message->thread->id,
                'size'     => $message->size ?? 0,
            ];
        }

        return [
            'accountId'  => (string) $account->id,
            'oldState'   => $oldState,
            'newState'   => $this->states->stateFor($account->id, JmapObjectType::Email),
            'created'    => [] === $created ? null : $created,
            'notCreated' => [] === $notCreated ? null : $notCreated,
        ];
    }
}
