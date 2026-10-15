<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Calendar\CalendarSwitchSkip;
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
 * One choice of three: every view, or the mail and one of the calendar's two.
 * It is a CalendarSwitchSkip and is stored as one, so there is no combination
 * for this controller to refuse.
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
        // form that did not mention the choice must not move it. A value that
        // is not one of the three is not a choice either, and is left out the
        // same way rather than read as the default — which would quietly undo
        // what was stored.
        $skip = CalendarSwitchSkip::tryFrom((string) $request->request->get('skip'));

        if (null !== $skip) {
            $user->calendarSwitchSkip = $skip;
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'calendar-behaviour']);
    }
}
