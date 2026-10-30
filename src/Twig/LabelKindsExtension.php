<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Label\LabelKinds;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `move_labels(labels, account?)` and `tag_labels(labels, account?)`: the
 * labels the "Move to" and "Label as" pickers offer — places and tags, per
 * account where the picker is for one conversation (LabelKinds).
 *
 * `is_place(label, account)` for the folder rows under an account, and
 * `in_labels(label)` for the sidebar's LABELS section, which holds the tags.
 */
final class LabelKindsExtension extends AbstractExtension
{
    public function __construct(
        private readonly LabelKinds $kinds,
        private readonly Security   $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('move_labels', $this->places(...)),
            new TwigFunction('tag_labels', $this->tags(...)),
            new TwigFunction('is_place', $this->kinds->isPlaceOn(...)),
            new TwigFunction('in_labels', $this->inLabels(...)),
        ];
    }

    /**
     * @param Label[] $labels
     *
     * @return Label[]
     */
    public function places(array $labels, ?Account $account = null): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->kinds->places($labels, $user, $account) : [];
    }

    /**
     * @param Label[] $labels
     *
     * @return Label[]
     */
    public function tags(array $labels, ?Account $account = null): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->kinds->tags($labels, $user, $account) : [];
    }

    public function inLabels(Label $label): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->kinds->inLabels($label, $user);
    }
}
