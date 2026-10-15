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
 * Stored now and read by nothing yet: the setting belongs with the key it is
 * about, and the person can set it in the same place they manage the key.
 */
enum RecipientPolicy: string
{
    case Auto   = 'auto';
    case Always = 'always';
    case Never  = 'never';
}
