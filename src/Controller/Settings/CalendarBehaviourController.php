<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Calendar → Behaviour: how the topbar's calendar button moves
 * between views.
 *
 * Two switches, "skip the split view" and "skip the full-width calendar",
 * that exclude each other. The exclusion lives in the setters on User, not
 * here: this controller records what was posted, and the entity makes sure the
 * button is never left with nowhere to go.
 *
 * Each switch is its own form and posts only its own field. A single form
 * posting both would arrive with the other switch's old value alongside the new
 * one, and nothing server-side could tell which of the two the person had just
 * moved.
 */
#[IsGranted('ROLE_USER')]
final class CalendarBehaviourController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/calendar-behaviour', name: 'app_settings_calendar_behaviour_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-calendar-behaviour');

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention a switch must not move it.
        if (true === $request->request->has('skipSplit')) {
            $user->calendarSkipSplit = '1' === (string) $request->request->get('skipSplit');
        }

        if (true === $request->request->has('splitOnly')) {
            $user->calendarSplitOnly = '1' === (string) $request->request->get('splitOnly');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'calendar-behaviour']);
    }
}
