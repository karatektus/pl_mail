<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use LogicException;

/**
 * Something tried to change a contact in a read-only address book — a shared
 * book, a global address list.
 *
 * The screens and JMAP check Contact::isEditable() first and answer with a
 * message of their own; this is the backstop ContactSyncTracker throws when a
 * path forgot to, so the flush fails instead of writing an edit the server
 * would refuse and the next pull would quietly undo.
 */
final class ContactReadOnlyException extends LogicException
{
}
