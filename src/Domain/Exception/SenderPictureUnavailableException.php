<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Nobody answered a sender picture lookup usefully (#639): a timeout, DNS that
 * does not resolve, a server error.
 *
 * Not recoverable in Messenger's sense, deliberately. The job does not throw
 * it on: it records the lookup as Failed, which is asked again the next day
 * by the scheduled refresh. Letting Messenger retry it would spend the
 * maintenance queue's retries — and its place in line ahead of contact
 * harvesting and rule runs — on a stranger's website being down.
 */
final class SenderPictureUnavailableException extends \RuntimeException
{
}
