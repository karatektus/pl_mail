<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use Symfony\Component\Mercure\Update;

/**
 * The one way this application builds a Mercure update: always private.
 *
 * Every topic plMail publishes to is somebody's own stream (`mail/user/<id>`),
 * and what goes down it is theirs — sync events, label markup with CSRF tokens
 * in it, the subject of a send that failed. A PUBLIC update is delivered to any
 * subscriber who names the topic, authorized or not, so the per-user topic
 * grant in the subscriber cookie (MercureCookieSubscriber, MercureAuthController)
 * protected nothing: anyone could open `mail/user/<somebody else>` and read
 * along. A private update reaches only subscribers whose JWT lists a matching
 * topic, which is exactly the grant that cookie already carries.
 *
 * A factory rather than `private: true` at each call site because the flag is
 * the kind of argument a new notifier forgets and review does not catch;
 * MercureUpdatesArePrivateTest fails any `new Update(` outside this file.
 */
final class UserUpdate
{
    /**
     * @param list<string> $topics
     */
    public static function create(array $topics, string $data): Update
    {
        return new Update(topics: $topics, data: $data, private: true);
    }
}
