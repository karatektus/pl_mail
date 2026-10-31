<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * The server cannot say what changed since the state we hold.
 *
 * RFC 8620 §5.2's `cannotCalculateChanges`: the state is too old, or the
 * server does not keep a log that long. The only answer is to stop asking
 * for changes and read the whole collection again — what Gmail's 404 on an
 * expired historyId and Graph's 410 on a dead delta link already mean for
 * those providers, and what GraphResyncRequiredException is to Graph.
 */
final class JmapResyncRequiredException extends JmapApiException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message, 0, 'cannotCalculateChanges');
    }
}
