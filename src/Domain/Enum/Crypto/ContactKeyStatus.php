<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Whether a contact's key is the one plMail uses for that address, or one
 * that turned up after it and waits for the person to decide.
 *
 * Trust on first use: the first key found for an address becomes Current by
 * itself. Every different key after that is Changed — recorded, warned about,
 * and never used in place of the Current one until the person accepts it. A
 * new key from a contact who lost the old one and a key from somebody posing
 * as them look exactly alike from here, and only the person can tell them
 * apart, by asking.
 *
 * At most one Current key per address; a unique partial index holds that.
 */
enum ContactKeyStatus: string
{
    case Current = 'current';
    case Changed = 'changed';
}
