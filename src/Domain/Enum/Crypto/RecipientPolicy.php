<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What to do about encrypting, or signing, mail to one recipient.
 *
 * Auto follows the rule compose will apply (phase 5 of issue #28): encrypt
 * when every recipient has a key, sign when the person signs by default.
 * Always and Never are the person overruling it for this one address — a
 * contact whose client mangles signed mail, say, or one who must never get
 * anything in the clear.
 *
 * The composer reads it (compose--encryption, #plan()): Never keeps a message
 * on automatic unencrypted, Always refuses to send it unencrypted to some
 * recipients because others lack a key. A contact's sits on their key, where
 * the person manages it; one of the person's own addresses keeps it in their
 * settings (User::$ownAddressEncrypt). Both are set from the lock's popover
 * in the composer as well (RecipientPolicies, #703).
 */
enum RecipientPolicy: string
{
    case Auto   = 'auto';
    case Always = 'always';
    case Never  = 'never';
}
