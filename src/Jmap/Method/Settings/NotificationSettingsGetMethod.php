<?php

declare(strict_types=1);

namespace App\Jmap\Method\Settings;

use App\Jmap\Mapper\NotificationSettingsMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;

/**
 * "NotificationSettings/get" — plMail extension,
 * `urn:plmail:params:jmap:notifications`.
 *
 * What mail is worth a notification, for the whole user (#369): the switches
 * that are on, as NotifyScope keys, and whether a browser drops a
 * notification for mail read in the meantime. The server applies the
 * switches to the browsers itself; a phone app reads them here and applies
 * them to its own pushes, which it cannot be spared because they also drive
 * its sync.
 *
 * A singleton with no accountId, like Appearance/get and for the same reason:
 * the choice belongs to the person, not to one of their mail accounts.
 */
final class NotificationSettingsGetMethod implements JmapMethod
{
    public function __construct(
        private readonly NotificationSettingsMapper $mapper,
    ) {
    }

    public function name(): string
    {
        return 'NotificationSettings/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        if (true === array_key_exists('accountId', $arguments) && null !== $arguments['accountId']) {
            throw new MethodException(
                'invalidArguments',
                'NotificationSettings are per user, not per account; "accountId" is not accepted.',
            );
        }

        $object     = $this->mapper->toJmap($context->user);
        $properties = $arguments['properties'] ?? null;

        if (null !== $properties) {
            if (false === is_array($properties)) {
                throw new MethodException('invalidArguments', '"properties" must be an array or null.');
            }

            foreach ($properties as $property) {
                // Named rather than dropped, as Appearance/get does.
                if ('id' !== $property && false === in_array($property, NotificationSettingsMapper::PROPERTIES, true)) {
                    throw new MethodException('invalidArguments', sprintf(
                        '"%s" is not a NotificationSettings property. Use one of: %s.',
                        is_scalar($property) ? (string) $property : gettype($property),
                        implode(', ', NotificationSettingsMapper::PROPERTIES),
                    ));
                }
            }

            $object = array_intersect_key($object, array_flip([...array_map('strval', $properties), 'id']));
        }

        $ids = $arguments['ids'] ?? null;

        if (null !== $ids && false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        $list     = [];
        $notFound = [];

        foreach ($ids ?? [NotificationSettingsMapper::SINGLETON_ID] as $id) {
            $id = $context->resolveId((string) $id) ?? (string) $id;

            if (NotificationSettingsMapper::SINGLETON_ID === $id) {
                $list[] = $object;
                continue;
            }

            $notFound[] = $id;
        }

        return [
            'state'    => $this->mapper->state($context->user),
            'list'     => $list,
            'notFound' => $notFound,
        ];
    }
}
