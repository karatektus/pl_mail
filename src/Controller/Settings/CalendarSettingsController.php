<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Form\CalendarType;
use App\Repository\Calendar\CalendarRepository;
use App\Service\User\UserTimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Managing calendars, in settings.
 *
 * The entity has carried a name, a colour, a zone, a visibility flag, a default
 * flag and a sort order since the first pass, and nothing reached any of them:
 * calendars were provisioned and then unchangeable, so a user with four
 * accounts had four calendars named after their usernames, all of them shown,
 * and no way to say where a new event should land.
 *
 * Shaped after LabelController, which is the same problem — user-owned things
 * in a list, edited in a modal, answered with one Turbo Stream that refreshes
 * every region showing them. Two rules differ, and both come from the fact that
 * a calendar can be *provisioned* where a label cannot:
 *
 *   A calendar the application created is not deletable — CalendarRole says
 *   which — because deleting it only means the provisioner makes it again, and
 *   the events go with it in the meantime.
 *
 *   Exactly one calendar is the default, and the invariant is kept here rather
 *   than by the database: making one default clears the other, in the same unit
 *   of work, because two defaults means new events land somewhere decided by
 *   row order.
 */
#[Route('/settings/calendars', name: 'app_settings_calendar_')]
#[IsGranted('IS_AUTHENTICATED')]
final class CalendarSettingsController extends AbstractController
{
    public function __construct(
        private readonly CalendarRepository     $calendars,
        private readonly UserTimezoneResolver   $timezones,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user     = $this->currentUser();
        $calendar = $this->blank($user);

        $form = $this->createForm(CalendarType::class, $calendar, [
            'action' => $this->generateUrl('app_settings_calendar_new'),
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->em->persist($calendar);
            $this->em->flush();

            return $this->calendarListStream('calendar.toast.created');
        }

        return $this->renderForm($form, $calendar);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Calendar $calendar): Response
    {
        $this->assertOwned($calendar);

        $form = $this->createForm(CalendarType::class, $calendar, [
            'action' => $this->generateUrl('app_settings_calendar_edit', ['id' => $calendar->id]),
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->em->flush();

            return $this->calendarListStream('calendar.toast.updated');
        }

        return $this->renderForm($form, $calendar);
    }

    /**
     * Deletes the calendar and, by the cascade on the join column, its events.
     *
     * That is the whole reason the confirmation names the count: a calendar is
     * not a folder that can be emptied first, and "delete Work" is a very
     * different sentence when Work holds two hundred appointments.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Calendar $calendar): Response
    {
        $this->assertOwned($calendar);
        $this->assertCsrf($request, 'calendar-delete' . $calendar->id);

        // Both guards are also what the list renders on, so reaching either is
        // a crafted request. The second is the one worth naming: removing the
        // default leaves nothing for a new event to land in until the
        // provisioner notices and makes a fresh one, which arrives as a
        // calendar the user never created.
        if (false === $calendar->role->isDeletable() || true === $calendar->isDefault) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($calendar);
        $this->em->flush();

        return $this->calendarListStream('calendar.toast.deleted');
    }

    /**
     * Show or hide a calendar in every view without deleting it — the flag
     * CalendarRepository::findVisibleForUser() reads, so this is what takes a
     * calendar out of the grid, the agenda and the upcoming-event dot at once.
     */
    #[Route('/{id}/toggle-visibility', name: 'toggle_visibility', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Request $request, Calendar $calendar): Response
    {
        $this->assertOwned($calendar);
        $this->assertCsrf($request, 'calendar-visibility' . $calendar->id);

        // The default stays visible. Hiding it means new events disappear the
        // moment they are created, which reads as the save having failed.
        if (true === $calendar->isDefault && true === $calendar->isVisible) {
            throw $this->createAccessDeniedException();
        }

        $calendar->isVisible = false === $calendar->isVisible;
        $this->em->flush();

        return $this->calendarListStream(
            true === $calendar->isVisible ? 'calendar.toast.shown' : 'calendar.toast.hidden',
        );
    }

    /** Where a new event lands when nothing else picked. */
    #[Route('/{id}/default', name: 'make_default', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function makeDefault(Request $request, Calendar $calendar): Response
    {
        $this->assertOwned($calendar);
        $this->assertCsrf($request, 'calendar-default' . $calendar->id);

        foreach ($this->calendars->findForUser($this->currentUser()) as $sibling) {
            $sibling->isDefault = $sibling === $calendar;
        }

        // A default nobody can see is a default whose events nobody can see.
        $calendar->isVisible = true;

        $this->em->flush();

        return $this->calendarListStream('calendar.toast.default_set');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A new calendar, already carrying the answers a user should not have to
     * give: their own zone rather than the server's, and the next colour along
     * so two calendars made in a row do not look like one.
     */
    private function blank(User $user): Calendar
    {
        $siblings = $this->calendars->findForUser($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->timeZone  = $this->timezones->nameFor($user);
        $calendar->color     = Calendar::COLORS[count($siblings) % count(Calendar::COLORS)];
        $calendar->sortOrder = count($siblings);

        return $calendar;
    }

    /**
     * One response for every mutation, refreshing the settings list. A stream
     * whose target is absent is a no-op, which is what lets the same response
     * serve a modal opened from anywhere.
     */
    private function calendarListStream(string $toastMessage): Response
    {
        return $this->render('calendar/_lists.stream.html.twig', [
            'toastMessage' => $toastMessage,
            'calendars'    => $this->calendars->findForUser($this->currentUser()),
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * A submitted-but-invalid form must come back 422, not 200: the modal
     * controller closes the dialog on any successful turbo:submit-end, so a 200
     * would swallow the errors and look like a silent save. Same reason
     * LabelController::renderForm() does this.
     */
    private function renderForm(FormInterface $form, Calendar $calendar): Response
    {
        $status = true === $form->isSubmitted() && false === $form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('calendar/_form.html.twig', [
            'form'     => $form,
            'calendar' => $calendar,
        ], new Response(status: $status));
    }

    private function assertOwned(Calendar $calendar): void
    {
        if ($calendar->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (false === $this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
