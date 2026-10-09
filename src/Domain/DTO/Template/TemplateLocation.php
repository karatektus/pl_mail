<?php

declare(strict_types=1);

namespace App\Domain\DTO\Template;

use App\Entity\Mail\Account;
use App\Entity\Template\TemplateFolder;

/**
 * A place in the template tree: the top level, an account, or a folder.
 *
 * It exists because a place is two columns (see TemplateFolder), and two
 * nullable arguments travelling side by side is how one of them ends up
 * belonging to a different account than the other. Built only by
 * TemplateLibrary, which is what makes "the folder is under this account" true
 * of every instance.
 *
 * `key` is how a place is named in a form field or a URL: `root`,
 * `account:12`, `folder:7`.
 */
final readonly class TemplateLocation
{
    public const string ROOT = 'root';

    public function __construct(
        public ?Account        $account = null,
        public ?TemplateFolder $folder = null,
    ) {
    }

    public function key(): string
    {
        return match (true) {
            null !== $this->folder  => sprintf('folder:%d', $this->folder->id),
            null !== $this->account => sprintf('account:%d', $this->account->id),
            default                 => self::ROOT,
        };
    }
}
