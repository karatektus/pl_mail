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
 * One endpoint: which insight extractors this user has switched off.
 *
 * The setting stores DISABLED keys rather than enabled ones — see
 * User::SETTING_INSIGHTS_DISABLED for why — so "toggle on" is a removal and
 * "toggle off" an addition, and an extractor nobody ever touched is simply
 * absent from the list.
 */
#[Route('/settings/insights', name: 'app_settings_insights_')]
#[IsGranted('ROLE_USER')]
final class InsightSettingsController extends AbstractController
{
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

        $key = $request->request->getString('key');

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

        if (true === $request->request->getBoolean('enabled')) {
            $disabled = array_values(array_diff($disabled, [$key]));
        } elseif (false === in_array($key, $disabled, true)) {
            $disabled[] = $key;
        }

        $user->setSetting(User::SETTING_INSIGHTS_DISABLED, $disabled);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }
}
