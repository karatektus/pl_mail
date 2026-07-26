<?php

declare(strict_types=1);

namespace App\Jmap\Protocol\Exception;

/**
 * The client declared a capability in "using" that this server does not support.
 * Maps to "urn:ietf:params:jmap:error:unknownCapability".
 */
final class UnknownCapabilityException extends JmapException
{
}
