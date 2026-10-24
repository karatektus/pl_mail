<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Whether a user key can be opened with the password the account has now.
 *
 * A key is locked when the password it was wrapped with is gone — an operator
 * reset it with `app:user:password`, or the person forgot it — and not when
 * anything is wrong with the key itself. Locked keys are kept: the mail
 * encrypted to the address keys beneath them is only readable again once a
 * recovery copy (a recovery phrase, a known device) opens the key and the
 * browser wraps it with the current password. See App\Entity\Crypto\UserKey.
 */
enum UserKeyStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
}
