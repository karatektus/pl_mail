<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\SwipeAction;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Mail → Swipe actions: what a swipe to the right and a swipe to the
 * left does on a row.
 *
 * One select per direction, each posting only its own field. The choice is a
 * SwipeAction, and the enum has no trash case on purpose (see SwipeAction), so a
 * posted "trash" is refused rather than stored: the whole post is refused, and
 * the valid direction posted beside it is not applied either.
 */
#[IsGranted('ROLE_USER')]
final class SwipeActionController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/swipe-actions', name: 'app_settings_swipe_actions_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-swipe-actions');

        // Both fields are read and checked before either is stored, so a refused
        // value cannot leave the other direction half-applied.
        $right = $this->postedAction($request, 'swipeRight');
        $left  = $this->postedAction($request, 'swipeLeft');

        if (null !== $right) {
            $user->swipeRightAction = $right;
        }

        if (null !== $left) {
            $user->swipeLeftAction = $left;
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'swipe-actions']);
    }

    /**
     * The action posted for a field, or null when the field was not posted at
     * all (a form that did not mention the direction must not move it). A value
     * that is posted but is not a choice is refused with a 404, as the clock
     * settings refuse theirs.
     */
    private function postedAction(Request $request, string $field): ?SwipeAction
    {
        if (false === $request->request->has($field)) {
            return null;
        }

        return SwipeAction::tryFrom((string) $request->request->get($field))
            ?? throw $this->createNotFoundException('Unknown swipe action.');
    }
}
