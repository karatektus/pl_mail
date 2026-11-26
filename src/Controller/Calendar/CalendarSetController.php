<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Calendar\CalendarSet;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Calendar\CalendarSets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The calendar toolbar's sets menu: pick a set, tick a calendar on or off, and
 * save what is showing as a set.
 *
 * Every action answers with a redirect to the page it came from. Inside the
 * docked pane that page is the pane's own frame, so Turbo redraws the calendar
 * and leaves the mail beside it alone.
 */
#[Route('/calendar/sets', name: 'app_calendar_set_')]
#[IsGranted('IS_AUTHENTICATED')]
final class CalendarSetController extends AbstractController
{
    use ChecksCsrf;

    public const string CSRF = 'calendar-sets';

    public function __construct(
        private readonly CalendarSets           $sets,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/apply', name: 'apply', requirements: ['id' => '[0-9a-f]+'], methods: ['POST'])]
    public function apply(Request $request, #[CurrentUser] User $user, string $id): RedirectResponse
    {
        $this->sets->apply($user, $this->owned($request, $user, $id));
        $this->em->flush();

        return $this->back($request);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf($request, self::CSRF);

        $name = trim($request->request->getString('name'));

        if ('' !== $name) {
            $this->sets->saveVisible($user, $name);
            $this->em->flush();
        }

        return $this->back($request);
    }

    #[Route('/{id}/update', name: 'update', requirements: ['id' => '[0-9a-f]+'], methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] User $user, string $id): RedirectResponse
    {
        $this->sets->updateToVisible($user, $this->owned($request, $user, $id));
        $this->em->flush();

        return $this->back($request);
    }

    #[Route('/{id}/rename', name: 'rename', requirements: ['id' => '[0-9a-f]+'], methods: ['POST'])]
    public function rename(Request $request, #[CurrentUser] User $user, string $id): RedirectResponse
    {
        $set  = $this->owned($request, $user, $id);
        $name = trim($request->request->getString('name'));

        if ('' !== $name) {
            $this->sets->rename($user, $set, $name);
            $this->em->flush();
        }

        return $this->back($request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '[0-9a-f]+'], methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, string $id): RedirectResponse
    {
        $this->sets->delete($user, $this->owned($request, $user, $id));
        $this->em->flush();

        return $this->back($request);
    }

    /** Shows or hides one calendar. The default can be shown but not hidden, as in settings. */
    #[Route('/calendar/{id}/toggle', name: 'toggle_calendar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleCalendar(Request $request, Calendar $calendar): RedirectResponse
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $calendar);
        $this->assertCsrf($request, self::CSRF);

        if (false === ($calendar->isDefault && $calendar->isVisible)) {
            $calendar->isVisible = false === $calendar->isVisible;
            $this->em->flush();
        }

        return $this->back($request);
    }

    private function owned(Request $request, User $user, string $id): CalendarSet
    {
        $this->assertCsrf($request, self::CSRF);

        return $this->sets->find($user, $id) ?? throw $this->createNotFoundException();
    }

    /**
     * Back to the local page the form was on, and never anywhere else: the
     * rule CalendarController::safeReturnTo() explains, `/\t/evil.test`
     * included.
     */
    private function back(Request $request): RedirectResponse
    {
        $return = $request->request->getString('return');

        if (1 !== preg_match('#^/(?![/\\\\])[^\x00-\x20\x7F\\\\]*$#D', $return)) {
            $return = $this->generateUrl('app_calendar_index');
        }

        return $this->redirect($return, RedirectResponse::HTTP_SEE_OTHER);
    }
}
