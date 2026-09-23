<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Entity\Mail\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * The server folder a Mailbox row stands for — found by its PATH, never its name.
 *
 * `Mailbox::$name` is the leaf ("Invoices"), and webklex's getFolder() looks a
 * delimiter-less string up by that leaf and takes the FIRST match. On an account
 * with "Archive/Invoices" and "Projects/Invoices" both rows therefore opened the
 * same server folder: one was synced twice and the other never, and a flag or a
 * move meant for one landed in the other.
 *
 * `$fullPath` is stored as the server spelled it (modified UTF-7, see
 * MailboxSyncer), so it is matched as UTF-7 rather than re-encoded. The name is
 * only a fallback for a row that predates the column.
 */
final class ImapFolderLocator
{
    public static function of(Client $client, Mailbox $mailbox): ?Folder
    {
        $path = (string) $mailbox->fullPath;

        if ('' !== $path) {
            return $client->getFolderByPath($path, true, true);
        }

        return $client->getFolderByName((string) $mailbox->name, true);
    }
}
