<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Entity\User\User;

/**
 * The user's notification choices (#369) as the JMAP NotificationSettings
 * object: a singleton, like Appearance, shared by NotificationSettings/get and
 * /set so the two cannot disagree about what a property is called.
 *
 * `scopes` is the list of switches that are on, ALWAYS spelled out — never
 * null for "the default". A client deciding whether to ring the phone should
 * not have to know what the server's default is; it asks whether a key is in
 * the list. The keys are NotifyScope's, which are the Android app's own.
 */
final class NotificationSettingsMapper
{
    public const string SINGLETON_ID = 'singleton';

    /** @var list<string> */
    public const array PROPERTIES = ['scopes', 'skipRead'];

    /**
     * @return array{id: string, scopes: list<string>, skipRead: bool}
     */
    public function toJmap(User $user): array
    {
        return [
            'id'       => self::SINGLETON_ID,
            'scopes'   => $user->notifyScopes,
            'skipRead' => $user->notifySkipsRead,
        ];
    }

    /**
     * A hash of the object, for the reason AppearanceMapper::state() gives:
     * this belongs to the user, the change log is per mail account, so there
     * is no sequence to read a state from. A client that finds it moved reads
     * the one object again.
     */
    public function state(User $user): string
    {
        return substr(hash('xxh128', json_encode($this->toJmap($user), JSON_THROW_ON_ERROR)), 0, 16);
    }
}
