<?php

declare(strict_types=1);

namespace App\Jmap\Protocol\Exception;

/**
 * A request-level problem: the whole request is rejected before any method runs.
 * Maps to the JMAP problem type "urn:ietf:params:jmap:error:notRequest".
 */
final class InvalidRequestException extends JmapException
{
}
