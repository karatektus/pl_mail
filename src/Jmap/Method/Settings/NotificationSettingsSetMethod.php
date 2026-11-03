<?php

declare(strict_types=1);

namespace App\Jmap\Method\Settings;

use App\Domain\DTO\Push\NotifyScope;
use App\Jmap\Mapper\NotificationSettingsMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "NotificationSettings/set" — plMail extension,
 * `urn:plmail:params:jmap:notifications`.
 *
 * The singleton half of RFC 8620 §5.3, as Appearance/set does it: `create`
 * and `destroy` are answered with the `singleton` SetError, and only
 * `update: {"singleton": …}` does anything.
 *
 * `scopes` replaces the whole list. A patch that toggles one switch would be
 * smaller, but the phone already holds the whole list after a get, and "the
 * list is what you sent" leaves nothing to reconcile. A key that could never
 * be a scope is refused rather than dropped, for Appearance/set's reason: a
 * client told it succeeded would go on believing a switch is on that the
 * server does not have. A key for a tab or label that no longer exists is
 * well-formed and kept — see NotifyScope::isWellFormed().
 */
final class NotificationSettingsSetMethod implements JmapMethod
{
    public function __construct(
        private readonly NotificationSettingsMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'NotificationSettings/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        if (true === array_key_exists('accountId', $arguments) && null !== $arguments['accountId']) {
            throw new MethodException(
                'invalidArguments',
                'NotificationSettings are per user, not per account; "accountId" is not accepted.',
            );
        }

        $user     = $context->user;
        $oldState = $this->mapper->state($user);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The notification settings have changed since ifInState was issued.');
        }

        $updated    = [];
        $notUpdated = [];
        $update     = $arguments['update'] ?? null;

        if (null !== $update && false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update ?? [] as $id => $patch) {
            $id = (string) $id;

            if (NotificationSettingsMapper::SINGLETON_ID !== $id) {
                $notUpdated[$id] = [
                    'type'        => 'notFound',
                    'description' => sprintf('NotificationSettings has one object, id "%s".', NotificationSettingsMapper::SINGLETON_ID),
                ];
                continue;
            }

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];
                continue;
            }

            try {
                $this->validate($patch);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            if (true === array_key_exists('scopes', $patch)) {
                $user->notifyScopes = $patch['scopes'];
            }

            if (true === array_key_exists('skipRead', $patch)) {
                $user->notifySkipsRead = $patch['skipRead'];
            }

            // The list as stored, when it is not the list as sent: the same
            // key twice is kept once. RFC 8620 §5.3 — what the server changed.
            $stored = $user->notifyScopes;

            $updated[$id] = true === array_key_exists('scopes', $patch) && $stored !== $patch['scopes']
                ? ['scopes' => $stored]
                : null;
        }

        $this->entityManager->flush();

        return [
            'oldState'     => $oldState,
            'newState'     => $this->mapper->state($user),
            'created'      => new \stdClass(),
            'notCreated'   => $this->refuse($arguments['create'] ?? null, 'create', 'A user has exactly one NotificationSettings; update "singleton" instead.'),
            'updated'      => [] === $updated ? new \stdClass() : $updated,
            'notUpdated'   => [] === $notUpdated ? new \stdClass() : $notUpdated,
            'destroyed'    => [],
            'notDestroyed' => $this->refuse($arguments['destroy'] ?? null, 'destroy', 'NotificationSettings cannot be destroyed; update "singleton" instead.'),
        ];
    }

    /**
     * The whole patch, mutating nothing: a refused update leaves nothing
     * behind.
     *
     * @param array<mixed> $patch
     */
    private function validate(array $patch): void
    {
        foreach ($patch as $property => $value) {
            $property = (string) $property;

            if ('id' === $property && NotificationSettingsMapper::SINGLETON_ID === $value) {
                continue;
            }

            if (false === in_array($property, NotificationSettingsMapper::PROPERTIES, true)) {
                throw new MethodException('invalidProperties', sprintf(
                    '"%s" is not a NotificationSettings property. Use one of: %s.',
                    $property,
                    implode(', ', NotificationSettingsMapper::PROPERTIES),
                ));
            }

            if ('skipRead' === $property && false === is_bool($value)) {
                throw new MethodException('invalidProperties', '"skipRead" must be true or false.');
            }

            if ('scopes' === $property) {
                if (false === is_array($value) || false === array_is_list($value)) {
                    throw new MethodException('invalidProperties', '"scopes" must be a list of scope keys.');
                }

                foreach ($value as $scope) {
                    if (false === is_string($scope) || false === NotifyScope::isWellFormed($scope)) {
                        throw new MethodException('invalidProperties', sprintf(
                            '"%s" is not a scope. Use "category:<built-in>", "tab:c<id>" or "label:<label id>".',
                            is_scalar($scope) ? (string) $scope : gettype($scope),
                        ));
                    }
                }
            }
        }
    }

    /**
     * create and destroy, both answered with the singleton SetError.
     */
    private function refuse(mixed $ids, string $argument, string $description): \stdClass|array
    {
        if (null === $ids) {
            return new \stdClass();
        }

        if (false === is_array($ids)) {
            throw new MethodException('invalidArguments', sprintf('"%s" has the wrong shape.', $argument));
        }

        $refused = [];

        foreach ('create' === $argument ? array_keys($ids) : $ids as $id) {
            $refused[(string) $id] = ['type' => 'singleton', 'description' => $description];
        }

        return [] === $refused ? new \stdClass() : $refused;
    }
}
