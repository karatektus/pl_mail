<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * The mail did not leave, and sending it unchanged will not get it out.
 *
 * Unrecoverable, so a refusal that does reach Messenger is not retried; in the
 * ordinary case SendMessageHandler catches it first, marks the draft and says
 * so at once.
 */
final class SendRefusedException extends SendFailureException implements UnrecoverableExceptionInterface
{
}
