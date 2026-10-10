<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Mail\Mailbox;

/**
 * One folder, and where it hangs in the tree of an account's folders.
 *
 * Carries what a row needs to draw the lines of a folder tree and a Mailbox
 * cannot say about itself: a folder knows its own path, not whether a sibling
 * follows it, and that is the whole difference between ├ and └.
 */
final readonly class FolderTreeRow
{
    /**
     * @param int        $depth       how many LISTED ancestors the folder has, 0 at the top
     * @param bool       $isLast      whether it is the last child of its parent
     * @param bool       $hasChildren whether anything hangs from it, so its icon grows a stem
     * @param list<bool> $continue one per ancestor level below the top, outermost first:
     *                             whether that ancestor has a sibling further down, which
     *                             is whether its vertical line runs on past this row
     */
    public function __construct(
        public Mailbox $mailbox,
        public int     $depth,
        public bool    $isLast,
        public bool    $hasChildren,
        public array   $continue,
    ) {}
}
