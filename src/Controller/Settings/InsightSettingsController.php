<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\User;
use App\Service\Insight\InsightExtractorInterface;
use App\Service\Insight\InsightExtractorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One endpoint: which of the switches on Settings → Insights this user has
 * turned off.
 *
 * The setting stores DISABLED keys rather than enabled ones — see
 * User::SETTING_INSIGHTS_DISABLED for why — so "toggle on" is a removal and
 * "toggle off" an addition, and an extractor nobody ever touched is simply
 * absent from the list.
 *
 * Two kinds of switch arrive here, not one. Most rows are extractors, keyed by
 * the registry. One row is the strip above the mail list, which is not an
 * extractor and does not live in that list — see PANE_KEY. They share this
 * action because they share a control: the segments are one Stimulus
 * controller (assets/controllers/settings/insights_controller.js) posting
 * `key` and `enabled` for whichever row moved, and giving the strip an
 * endpoint of its own would mean a second controller, a second token and a
 * second revert-on-failure, all to write a different key in the same bag.
 */
#[Route('/settings/insights', name: 'app_settings_insights_')]
#[IsGranted('ROLE_USER')]
final class InsightSettingsController extends AbstractController
{
    /**
     * The row that is not an extractor: the strip above the mail list.
     *
     * Reserved rather than derived, and checked before the registry, so an
     * extractor shipped later under this key cannot quietly take the strip's
     * switch away from it. It is safe to reserve because it names a REGION and
     * every extractor key names a SOURCE — `parcel`, `flight`, `invoice` — so
     * nothing that belongs in the registry would ever want it.
     *
     * It writes User::SETTING_INSIGHT_PANE_DISABLED and not a member of
     * SETTING_INSIGHTS_DISABLED, which is the whole distinction that constant's
     * doc draws: switching the strip off hides a band above the inbox, it does
     * not stop a single fact being found. The extractors keep running, the
     * radar panel keeps filling, and the strips inside conversations stay.
     */
    public const string PANE_KEY = 'pane';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InsightExtractorRegistry $extractors,
    ) {
    }

    /**
     * Flip one extractor on or off for the current user.
     *
     * Token-checked like AppearanceController::paneState and for the reason
     * pinned there: this is a new state-changing POST, and new ones carry
     * tokens whatever their blast radius.
     *
     * The key is validated against the registry rather than trusted: the
     * setting is a list read back by the harvester, and letting arbitrary
     * strings in would turn a preference bag into a free-form store. Unknown
     * is a 400, not a silent write — the only caller is our own settings page,
     * so an unknown key is a bug, and a bug should say so.
     */
    #[Route('/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        if (false === $this->isCsrfTokenValid('insights_toggle', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        $key     = $request->request->getString('key');
        $enabled = $request->request->getBoolean('enabled');

        if (self::PANE_KEY === $key) {
            // DISABLED and not ENABLED, so absent still means on: a user who
            // never opened this page has no key, and InsightPane reads that
            // absence as the feature being on. Switching it back on writes
            // false rather than removing the key — the bag has no remove, and
            // false and absent are the same answer to isDisabledFor().
            $user->setSetting(User::SETTING_INSIGHT_PANE_DISABLED, false === $enabled);

            $this->entityManager->flush();

            return $this->json(['ok' => true]);
        }

        $known = array_map(
            static fn (InsightExtractorInterface $extractor): string => $extractor::key(),
            $this->extractors->all(),
        );

        if (false === in_array($key, $known, true)) {
            return $this->json(
                ['ok' => false, 'error' => 'unknown extractor'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $stored   = $user->getSetting(User::SETTING_INSIGHTS_DISABLED);
        $disabled = is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];

        if (true === $enabled) {
            $disabled = array_values(array_diff($disabled, [$key]));
        } elseif (false === in_array($key, $disabled, true)) {
            $disabled[] = $key;
        }

        $user->setSetting(User::SETTING_INSIGHTS_DISABLED, $disabled);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }
}
