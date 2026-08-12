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
use App\Service\Mail\SignatureProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Identity/set" (RFC 8621 §6.3). An Identity is a plMail EmailAlias.
 *
 * Same rules the web UI enforces in AliasController: an address must be a
 * valid mailbox and unique on the account, the primary address cannot be
 * removed, and provider-discovered aliases are not the client's to delete —
 * they come back on the next sync.
 *
 * Signatures ARE stored — in the same per-alias setting the composer and the
 * settings panel read, so a signature written on a phone is the signature the
 * browser signs with and the other way round. `htmlSignature` is the value;
 * `textSignature` is accepted as a convenience for clients that only offer a
 * plain-text field, and is escaped into HTML when it arrives without one.
 *
 * replyTo and bcc are still rejected rather than accepted-and-dropped: plMail
 * has nowhere to store them, and a client that thinks it saved a per-identity
 * Bcc would silently send mail without it.
 */
final class IdentitySetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly EmailAliasRepository $aliasRepository,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly SignatureProvider $signatures,
    ) {
    }

    public function name(): string
    {
        return 'Identity/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

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
                $this->rejectUnsupported($properties, ['email', 'name', 'htmlSignature', 'textSignature']);

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
            // The id is needed for the response and for "#creationId" — and
            // for the signature, whose settings key IS the alias id.
            $this->entityManager->flush();

            $this->applySignature($account, $alias, $properties);

            $this->stateManager->recordCreated($account->id, JmapObjectType::Identity, (string) $alias->id);
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
                $this->rejectUnsupported($patch, ['name', 'htmlSignature', 'textSignature']);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            if (true === array_key_exists('name', $patch)) {
                $alias->displayName = $this->nameOrNull($patch['name']) ?? '';
            }

            $this->applySignature($account, $alias, $patch);

            $this->stateManager->recordUpdated($account->id, JmapObjectType::Identity, (string) $alias->id);
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

            $this->stateManager->recordDestroyed($account->id, JmapObjectType::Identity, (string) $alias->id);

            $account->removeAlias($alias);
            $this->entityManager->remove($alias);

            $destroyed[] = $id;
        }
    }

    /**
     * Store the signature a patch or a create carries, if it carries one.
     *
     * Absence of both keys means "leave it alone" — this is a patch, and a
     * client renaming an identity has said nothing about its signature. An
     * explicitly empty htmlSignature is a different statement and IS stored:
     * it means this address signs with nothing, which on an account that has a
     * signature is not the same as inheriting it. That is exactly the
     * presence-versus-value distinction Account::signatureAliasSetting()
     * documents, reached here from the other client.
     *
     * SANITISED, like every other way a signature can be written. This one is
     * the least trusted of the lot: it arrives over the API from whatever a
     * client chose to send.
     *
     * @param array<string,mixed> $properties
     */
    private function applySignature(Account $account, EmailAlias $alias, array $properties): void
    {
        $hasHtml = array_key_exists('htmlSignature', $properties);
        $hasText = array_key_exists('textSignature', $properties);

        if (false === $hasHtml && false === $hasText) {
            return;
        }

        // htmlSignature wins when both are given: it is the richer of the two
        // renderings of one value, and a client sending both is sending the
        // text form for readers that cannot show HTML.
        if (true === $hasHtml) {
            $html = $properties['htmlSignature'];

            if (false === is_string($html) && null !== $html) {
                throw new MethodException('invalidProperties', '"htmlSignature" must be a string.');
            }

            $account->setSetting(
                Account::signatureAliasSetting((int) $alias->id),
                $this->signatures->sanitize(null === $html ? '' : $html),
            );

            return;
        }

        $text = $properties['textSignature'];

        if (false === is_string($text) && null !== $text) {
            throw new MethodException('invalidProperties', '"textSignature" must be a string.');
        }

        // Escaped, never parsed: a plain-text signature that happened to
        // contain "<b>" is a signature containing those five characters.
        $html = '' === trim((string) $text)
            ? ''
            : nl2br(htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $account->setSetting(
            Account::signatureAliasSetting((int) $alias->id),
            $this->signatures->sanitize($html),
        );
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
                'Property "%s" is not supported; plMail stores no replyTo or bcc for an identity.',
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

        foreach ($account->aliases as $alias) {
            if ((string) $alias->id === $id) {
                return $alias;
            }
        }

        return null;
    }
}
