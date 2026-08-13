<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * What a label is CALLED on screen, for every surface that shows one.
 *
 * A system label's `name` column is its English creation name — "Inbox",
 * "Trash" — because that is what LabelRole::displayName() seeds the row with
 * and what the IMAP side of the app matches on. It is storage, not a caption.
 * The sidebar never printed it: its rows are hardcoded `sidebar.nav.*`
 * translations, which is why the German sidebar said "Posteingang".
 *
 * Settings → Labels printed `label.name` straight out of the database, so the
 * same six folders read "Inbox / Sent / Drafts…" one panel away from
 * "Posteingang / Gesendet / Entwürfe…" — the two lists disagreeing on screen at
 * the same time.
 *
 * So there is now one path to the name and both use it, rather than a second
 * translation table parallel to the sidebar's. A user label has no role and no
 * translation: its name is the name the user typed, and it is returned as-is.
 */
final class LabelNameExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('label_name', $this->name(...)),
            new TwigFilter('role_name', $this->roleName(...)),
        ];
    }

    public function name(Label $label): string
    {
        $role = $label->role;

        return null === $role
            ? (string) $label->name
            : $this->roleName($role);
    }

    /**
     * The sidebar's own key, deliberately: sharing it is what keeps the two
     * lists from drifting, and a `label.system.*` family beside it would be the
     * same strings maintained twice.
     */
    public function roleName(LabelRole $role): string
    {
        return $this->translator->trans('sidebar.nav.' . $role->value);
    }
}
