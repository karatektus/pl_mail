<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

/**
 * JMAP capability URNs (RFC 8620 / RFC 8621) advertised by this server.
 */
final class Capability
{
    public const string CORE = 'urn:ietf:params:jmap:core';
    public const string MAIL = 'urn:ietf:params:jmap:mail';
    public const string SUBMISSION = 'urn:ietf:params:jmap:submission';

    /**
     * Capabilities a client is currently allowed to declare in "using".
     * Grow this list as new object types come online.
     *
     * @var list<string>
     */
    public const array SUPPORTED = [
        self::CORE,
        self::MAIL,
    ];
}
