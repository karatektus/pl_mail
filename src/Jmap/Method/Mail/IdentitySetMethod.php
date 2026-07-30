<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\EmailAliasRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Identity/set" (RFC 8621 §6.3). An Identity is a plMail EmailAlias.
 *
 * Same rules the web UI enforces in AliasController: an address must be a
 * valid mailbox and unique on the account, the primary address cannot be
 * removed, and provider-discovered aliases are not the client's to delete —
 * they come back on the next sync.
 *
 * Signatures, replyTo and bcc are rejected rather than accepted-and-dropped.
 * plMail has nowhere to store them, and a client that thinks it saved a
 * signature would silently send mail without one.
 */
final class IdentitySetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly EmailAliasRepository $aliasRepository,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Identity/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $oldState = $this->stateManager->stateFor($accountId, JmapObjectType::Identity);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The account has changed since ifInState was issued.');
        }

        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];
        $destroyed = [];
        $notDestroyed = [];

        $this->applyCreates($account, $arguments['create'] ?? null, $context, $created, $notCreated);
        $this->applyUpdates($account, $arguments['update'] ?? null, $context, $updated, $notUpdated);
        $this->applyDestroys($account, $arguments['destroy'] ?? null, $context, $destroyed, $notDestroyed);

        $this->entityManager->flush();

        return [
            'accountId' => (string) $accountId,
            'oldState' => $oldState,
            'newState' => $this->stateManager->stateFor($accountId, JmapObjectType::Identity),
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => array_values($destroyed),
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $created
     * @param array<string,mixed> $notCreated
     */
    private function applyCreates(
        Account $account,
        mixed $create,
        JmapContext $context,
        array &$created,
        array &$notCreated,
    ): void {
        if (null === $create) {
            return;
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        foreach ($create as $creationId => $properties) {
            $creationId = (string) $creationId;

            if (false === is_array($properties)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];
                continue;
            }

            try {
                $address = $this->requireAddress($properties['email'] ?? null);
                $this->rejectUnsupported($properties, ['email', 'name']);

                if (null !== $this->aliasRepository->findOneByAccountAndAddress($account, $address)) {
                    throw new MethodException('invalidProperties', sprintf('"%s" is already an identity on this account.', $address));
                }
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();
                continue;
            }

            $alias = new EmailAlias(
                account: $account,
                address: $address,
                source: EmailAliasSource::Manual,
                status: EmailAliasStatus::Active,
                displayName: $this->nameOrNull($properties['name'] ?? null),
            );

            $account->addAlias($alias);
            $this->entityManager->persist($alias);
            // The id is needed for the response and for "#creationId".
            $this->entityManager->flush();

            $this->stateManager->recordCreated($account->getId(), JmapObjectType::Identity, (string) $alias->id);
            $context->recordCreatedId($creationId, (string) $alias->id);

            $created[$creationId] = [
                'id' => (string) $alias->id,
                'mayDelete' => true,
            ];
        }
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(
        Account $account,
        mixed $update,
        JmapContext $context,
        array &$updated,
        array &$notUpdated,
    ): void {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];
                continue;
            }

            $alias = $this->findAlias($account, $context->resolveId($id) ?? $id);

            if (null === $alias) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such Identity in this account.'];
                continue;
            }

            try {
                // "email" is create-only in the spec: changing it would mean
                // silently repointing an identity at a different mailbox.
                $this->rejectUnsupported($patch, ['name']);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            if (true === array_key_exists('name', $patch)) {
                $alias->displayName = $this->nameOrNull($patch['name']) ?? '';
            }

            $this->stateManager->recordUpdated($account->getId(), JmapObjectType::Identity, (string) $alias->id);
            $updated[$id] = null;
        }
    }

    /**
     * @param list<string>        $destroyed
     * @param array<string,mixed> $notDestroyed
     */
    private function applyDestroys(
        Account $account,
        mixed $destroy,
        JmapContext $context,
        array &$destroyed,
        array &$notDestroyed,
    ): void {
        if (null === $destroy) {
            return;
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id = (string) $id;
            $alias = $this->findAlias($account, $context->resolveId($id) ?? $id);

            if (null === $alias) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such Identity in this account.'];
                continue;
            }

            if (EmailAliasStatus::Primary === $alias->status) {
                $notDestroyed[$id] = [
                    'type' => 'forbidden',
                    'description' => 'The primary identity cannot be destroyed.',
                ];
                continue;
            }

            if (EmailAliasSource::Manual !== $alias->source) {
                $notDestroyed[$id] = [
                    'type' => 'forbidden',
                    'description' => 'This identity comes from the provider and would return on the next sync.',
                ];
                continue;
            }

            $this->stateManager->recordDestroyed($account->getId(), JmapObjectType::Identity, (string) $alias->id);

            $account->removeAlias($alias);
            $this->entityManager->remove($alias);

            $destroyed[] = $id;
        }
    }

    private function requireAddress(mixed $email): string
    {
        if (false === is_string($email)) {
            throw new MethodException('invalidProperties', 'A string "email" is required.');
        }

        $address = EmailAlias::normalize($email);

        if ('' === $address || false === filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new MethodException('invalidProperties', sprintf('"%s" is not a valid email address.', $email));
        }

        return $address;
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string>        $allowed
     */
    private function rejectUnsupported(array $properties, array $allowed): void
    {
        foreach (array_keys($properties) as $property) {
            if (true === in_array((string) $property, $allowed, true)) {
                continue;
            }

            throw new MethodException('invalidProperties', sprintf(
                'Property "%s" is not supported; plMail stores no signature, replyTo or bcc for an identity.',
                $property,
            ));
        }
    }

    private function nameOrNull(mixed $name): ?string
    {
        if (null === $name) {
            return null;
        }

        if (false === is_string($name)) {
            throw new MethodException('invalidProperties', '"name" must be a string.');
        }

        return $name;
    }

    private function findAlias(Account $account, string $id): ?EmailAlias
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        foreach ($account->getAliases() as $alias) {
            if ((string) $alias->id === $id) {
                return $alias;
            }
        }

        return null;
    }
}
