<?php

declare(strict_types=1);

namespace App\Domain\DTO\Template;

use App\Entity\Template\MailTemplate;

/**
 * Everything one user has in Settings → Templates, arranged as it is shown.
 *
 * Three parts in a fixed order: the templates at the top level, then one folder
 * per mail account, then the folders the user made beside them. The accounts
 * are always there, empty or not — that is the "mirrors your mail accounts"
 * the feature promises — and come before the user's own top-level folders so
 * the part of the tree that the app decides does not move when the user adds
 * to the part they decide.
 */
final readonly class TemplateTree
{
    /**
     * @param list<MailTemplate>     $templates the ones at the top level
     * @param list<TemplateTreeNode> $accounts
     * @param list<TemplateTreeNode> $folders
     */
    public function __construct(
        public array $templates,
        public array $accounts,
        public array $folders,
    ) {
    }

    /**
     * Every folder there is, top down, accounts first — the choices a "Folder"
     * dropdown offers beneath its "no folder" entry.
     *
     * @return list<TemplateTreeNode>
     */
    public function locations(): array
    {
        $nodes = [];

        foreach ([...$this->accounts, ...$this->folders] as $node) {
            array_push($nodes, ...$node->flattened());
        }

        return $nodes;
    }

    public function isEmpty(): bool
    {
        return [] === $this->templates && 0 === array_sum(array_map(
            static fn (TemplateTreeNode $node): int => $node->total(),
            [...$this->accounts, ...$this->folders],
        ));
    }
}
