<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Mail\InboxTabs;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The names of the signed-in reader's own inbox categories, for a template that
 * has an id and wants a word.
 *
 * A function rather than one more variable on every controller that renders the
 * filter list: that partial is drawn from three places, and a category chip is
 * the only thing in it that needs the names. Answered from InboxTabs, which
 * reads them once per request.
 *
 * Keyed `id12`, not `12`, for the reason the label map beside it is: Twig's
 * merge follows array_merge, which RENUMBERS integer keys, and a map that quietly
 * became positional would name the wrong category.
 */
final class InboxCategoryExtension extends AbstractExtension
{
    public function __construct(
        private readonly InboxTabs $tabs,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_category_names', $this->names(...)),
        ];
    }

    /**
     * @return array<string,string> 'id<categoryId>' => name; empty when nobody is signed in
     */
    public function names(): array
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return [];
        }

        $names = [];

        foreach ($this->tabs->customFor($user) as $tab) {
            $names['id' . $tab->categoryId] = (string) $tab->name;
        }

        return $names;
    }
}
