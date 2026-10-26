<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * plMail itself declines a folder action, before the server is asked.
 *
 * Not the server's refusal — that is ImapFolderRefusedException, with the
 * server's words. These are the rules plMail keeps on its own side, each for a
 * reason that is not the server's to know:
 *
 *   - SPECIAL_USE: the inbox, Sent, Drafts, Trash, Junk and Archive are never
 *     renamed or deleted from here. Other clients and the server itself find
 *     them by name or by RFC 6154 attribute, and plMail's sending, archiving
 *     and spam handling depend on them; a renamed Sent is a Sent nobody files
 *     into.
 *   - HAS_CHILDREN: deleting a folder with folders inside it either takes them
 *     along or leaves them under a placeholder, depending on the server
 *     (RFC 3501 §6.3.4). Neither is something to do by accident.
 *   - NOT_EMPTY: deleting a folder deletes its mail. The same rule
 *     RemoteLabelRemover keeps: empty on the server and empty here, or no.
 *
 * `$reason` is the last part of a translation key under
 * `settings.accounts.folders.refused.`.
 */
final class FolderActionRefusedException extends \RuntimeException
{
    public const string SPECIAL_USE  = 'special_use';
    public const string HAS_CHILDREN = 'has_children';
    public const string NOT_EMPTY    = 'not_empty';

    public function __construct(
        public readonly string $reason,
        public readonly int    $count = 0,
    ) {
        parent::__construct(sprintf('Folder action refused: %s', $reason));
    }
}
