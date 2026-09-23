<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A picture picked from a connected service could not become the avatar.
 *
 * A refusal the person can do something about — pick another picture, or try
 * again when the service is back — so it is answered on the form, beside the
 * picture field, and never as a server error. `reason` is the translation key
 * of what to tell them.
 */
final class AvatarRefusedException extends RuntimeException
{
    public const string NOT_AN_IMAGE = 'profile.avatar_from.not_an_image';
    public const string TOO_LARGE    = 'profile.avatar_from.too_large';
    public const string UNAVAILABLE  = 'profile.avatar_from.unavailable';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
