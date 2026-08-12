<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * A schedule the server will not accept, named by translation key rather than
 * by sentence.
 *
 * The reason goes back to the person who picked the time — "that is in the
 * past", "that is further ahead than this server will hold mail" — so it has
 * to arrive in their language. An exception message is a developer string; the
 * key is the part the controller can hand to the translator.
 */
final class InvalidScheduleException extends \RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
    ) {
        parent::__construct($translationKey);
    }
}
