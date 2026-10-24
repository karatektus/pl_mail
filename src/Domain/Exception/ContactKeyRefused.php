<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * Something done to a contact's key that plMail will not do, for a reason the
 * person can act on — pasted text that holds no public key, an address WKD
 * cannot be asked about, too many lookups in a row. Also what publishing the
 * person's own key to a key server refuses with (KeyPublishing): the same
 * kind of reason, often the very same one — the budget it shares with the
 * lookups, a server that could not be reached.
 *
 * Unlike KeyringRefused, which is always a browser's fault, this one is shown:
 * the message is a translation key, and the page says it in the person's
 * language.
 */
final class ContactKeyRefused extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
    ) {
        parent::__construct($translationKey);
    }
}
