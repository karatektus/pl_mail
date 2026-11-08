<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;

/**
 * One folder row under an account in the sidebar, with the folders inside it
 * (AccountFolderTree).
 */
final readonly class AccountFolderNode
{
    /**
     * @param list<AccountFolderNode> $children
     */
    public function __construct(
        public Label $label,
        public int   $depth,
        public array $children,
    ) {
    }
}
