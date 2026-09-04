<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Service\Ai\AiPermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ai_writing_help_enabled()` — whether the composer should offer to help.
 * `ai_summary_enabled()`      — whether the reading pane should offer a summary.
 * `ai_categorisation_enabled()` — whether sorting by assistant can be chosen.
 * `ai_settings_available()`   — whether the AI settings section exists at all.
 *
 * Functions rather than controller variables, for the reason `signature_map()`
 * is one: compose/_window.html.twig is included from three places — the two
 * routes that render it and both undo streams, which include it `only` — and
 * `strict_variables` is on, so a variable threaded through two of the three is
 * a 500 on the undo path rather than a missing button. The next caller would
 * have to remember, and forgetting is invisible until somebody cancels a send.
 *
 * The second one is here rather than in SettingsController for the same reason
 * with a different subject: the settings navigation is rendered on every
 * settings page, not only the AI one, so a controller variable would have to be
 * built on the labels tab too.
 */
final class AiAvailabilityExtension extends AbstractExtension
{
    public function __construct(
        private readonly AiPermissions $permissions,
        private readonly Security      $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ai_writing_help_enabled', $this->writingHelp(...)),
            new TwigFunction('ai_summary_enabled', $this->summary(...)),
            new TwigFunction('ai_categorisation_enabled', $this->categorisation(...)),
            new TwigFunction('ai_settings_available', $this->settingsAvailable(...)),
        ];
    }

    /**
     * Both switches, because the button is an offer and the endpoint is the
     * answer.
     *
     * Asking only the installation's would render a "help me write" button for
     * somebody who has switched drafting help off, whose every click would be
     * answered with a 409 by ComposeAssistController. A control that is always
     * refused is worse than no control.
     */
    public function writingHelp(): bool
    {
        $user = $this->security->getUser();

        return $this->permissions->allows($user instanceof User ? $user : null, AiFeature::WritingHelp);
    }

    /**
     * The same two switches, for the reading pane's summary card.
     *
     * A function rather than a controller variable for the reason above with a
     * different subject: mail/_thread_content.html.twig is rendered from two
     * paths — the full page and the bare fragment mail_pane_controller injects
     * — and is included from mail/thread.html.twig WITHOUT `only`, so a
     * variable threaded through one of them is a strict_variables 500 on the
     * other rather than a missing button. The stored summary itself does need
     * threading, because only a controller can read it; whether the feature
     * exists does not.
     */
    /**
     * The same two switches again, for the sorting card on the settings page.
     *
     * That card OFFERS the choice either way and disables the half that cannot
     * be honoured, rather than hiding it — a setting that vanishes is how
     * somebody concludes the feature does not exist. So this answers "may it be
     * chosen", not "should the control be drawn".
     */
    public function categorisation(): bool
    {
        $user = $this->security->getUser();

        return $this->permissions->allows($user instanceof User ? $user : null, AiFeature::Categorise);
    }

    public function summary(): bool
    {
        $user = $this->security->getUser();

        return $this->permissions->allows($user instanceof User ? $user : null, AiFeature::Summary);
    }

    /**
     * The ADMINISTRATOR'S switches only, and deliberately.
     *
     * If this asked the user's own as well, somebody who had switched all four
     * of theirs off would lose the navigation entry for the page that switches
     * them back on — a setting that can be turned off and not on again.
     */
    public function settingsAvailable(): bool
    {
        return $this->permissions->anyAdminEnabled();
    }
}
