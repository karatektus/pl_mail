<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\UnifiedInbox;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `unified_inbox_name(inbox)` — what a unified inbox is called on screen
 * (#265): the reader's own name for it, or plMail's word for an inbox that has
 * none — "Inbox", or "Other" for the catch-all — in the reader's language.
 *
 * One function rather than the fallback written out in each template, because
 * it is drawn in the sidebar, the list's title, the browser tab and settings,
 * and a place that spelled it differently would call the same row two things.
 */
final class UnifiedInboxExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unified_inbox_name', $this->name(...)),
        ];
    }

    public function name(UnifiedInbox $inbox): string
    {
        if (null !== $inbox->name && '' !== $inbox->name) {
            return $inbox->name;
        }

        return $this->translator->trans(true === $inbox->isOther ? 'sidebar.nav.inbox_other' : 'sidebar.nav.inbox');
    }
}
