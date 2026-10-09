<?php

declare(strict_types=1);

namespace App\Domain\DTO\Template;

use App\Entity\Template\MailTemplate;

/**
 * One folder of the template tree as it is drawn: an account, or a folder the
 * user made, with what is inside it.
 */
final readonly class TemplateTreeNode
{
    /**
     * @param string                 $path      the names from the top down to
     *                                          this folder, for a flat list
     *                                          that has no indentation to say
     *                                          it with ("work@… / Invoices")
     * @param list<TemplateTreeNode> $children
     * @param list<MailTemplate>     $templates the ones directly in this folder
     */
    public function __construct(
        public TemplateLocation $location,
        public string           $name,
        public string           $path,
        public int              $depth,
        public array            $children,
        public array            $templates,
    ) {
    }

    /** True for a folder that is a mail account, which the user cannot rename or delete. */
    public function isAccount(): bool
    {
        return null === $this->location->folder;
    }

    /** Everything under this folder, at any depth. */
    public function total(): int
    {
        return array_reduce(
            $this->children,
            static fn (int $sum, TemplateTreeNode $child): int => $sum + $child->total(),
            count($this->templates),
        );
    }

    /**
     * This folder and every folder under it, top down.
     *
     * @return list<TemplateTreeNode>
     */
    public function flattened(): array
    {
        $nodes = [$this];

        foreach ($this->children as $child) {
            array_push($nodes, ...$child->flattened());
        }

        return $nodes;
    }
}
