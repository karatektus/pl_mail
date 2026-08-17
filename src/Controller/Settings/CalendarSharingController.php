<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Domain\Helper\TimezoneHelper;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\BookingPageRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Calendar\CalendarShareLinkRepository;
use App\Service\Calendar\Booking\BookingPageWriter;
use App\Service\Calendar\Sharing\ShareLinkWriter;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Managing the two kinds of public calendar URL, from settings.
 *
 * One controller for share links and booking pages, and that is the one shape
 * decision worth arguing here. They are different entities with different forms
 * and no shared service, so two controllers would be the obvious split — but
 * they are one settings section, one list on screen, and one Turbo Stream that
 * redraws it, and the response every mutation answers with is that stream. Two
 * controllers would either duplicate the stream method or make one of them call
 * into the other, and IcsController's docblock already names the failure that
 * follows: a mutation redrawing only its own half leaves the other invisible
 * until the page is reloaded.
 *
 * They also share the decision the whole feature turns on, which is easier to
 * keep true in one file: **a token is shown once and never again.** Both
 * writers return the minted token, both create and regenerate actions build a
 * URL out of it and hand it to the list, and nothing persists it — see
 * PublicLinkToken. That is why there is no "copy link" button on an existing
 * row: there is nothing to copy, and a button that regenerated silently in
 * order to have something would break the URL the owner already sent.
 *
 * ── Hand-written forms, no FormType ───────────────────────────────────────
 *
 * Following calendar/_ics_subscribe.html.twig. Neither form maps cleanly: the
 * share link's fields are a checkbox set that becomes an enum list, a mode that
 * decides which two of four date fields matter, and a calendar picker whose ids
 * must be re-resolved against the user rather than bound. A FormType mapping
 * fields it must then ignore is worse than no FormType.
 *
 * ── Where the rules live ──────────────────────────────────────────────────
 *
 * Nowhere here. Ownership of a posted calendar id is re-resolved by the
 * writers, the numbers are clamped by BookingPageWriter, and what a link may
 * reveal is decided by ShareLinkReader at read time. This controller resolves,
 * authorises, delegates and renders — the ids it hands over are as posted,
 * because the writers are written to be handed exactly that.
 */
#[Route('/settings/sharing', name: 'app_settings_sharing_')]
#[IsGranted('IS_AUTHENTICATED')]
final class CalendarSharingController extends AbstractController
{
    use ChecksCsrf;

    /** One CSRF id for every mutation in this section — they are one form family. */
    private const string CSRF_ID = 'calendar-sharing';

    public function __construct(
        private readonly CalendarShareLinkRepository $links,
        private readonly BookingPageRepository       $pages,
        private readonly CalendarRepository          $calendars,
        private readonly ShareLinkWriter             $linkWriter,
        private readonly BookingPageWriter           $pageWriter,
        private readonly UserTimezoneResolver        $timezones,
        private readonly EntityManagerInterface      $em,
    ) {
    }

    // ── Share links ───────────────────────────────────────────────────────────

    #[Route('/link/new', name: 'link_new', methods: ['GET', 'POST'])]
    public function linkNew(Request $request): Response
    {
        $user = $this->currentUser();

        if (false === $request->isMethod('POST')) {
            return $this->render('settings/sharing/_link_form.html.twig', [
                'link'      => null,
                'calendars' => $this->calendars->findForUser($user),
                'details'   => ShareDetail::cases(),
            ]);
        }

        $this->assertCsrf($request, self::CSRF_ID);

        $token = $this->linkWriter->create(
            $user,
            $request->request->getString('name'),
            $request->request->all('details'),
            $request->request->all('calendars'),
            ShareWindow::tryFrom($request->request->getString('windowMode')) ?? ShareWindow::Rolling,
            (int) $request->request->getString('rollingDays'),
            $this->date($request->request->getString('startsOn')),
            $this->date($request->request->getString('endsOn')),
        );

        $this->em->flush();

        return $this->sharingStream(
            'calendar.share.toast.created',
            $this->generateUrl(
                'app_shared_calendar_show',
                ['token' => $token],
                // Absolute, because this string is about to be copied into a
                // chat window or an email. A path would be pasted somewhere it
                // resolves against the wrong host, or against none.
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
    }

    #[Route('/link/{id}/edit', name: 'link_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function linkEdit(Request $request, int $id): Response
    {
        $user = $this->currentUser();
        $link = $this->links->findOneForUser($user, $id);

        if (null === $link) {
            throw $this->createNotFoundException();
        }

        if (false === $request->isMethod('POST')) {
            return $this->render('settings/sharing/_link_form.html.twig', [
                'link'      => $link,
                'calendars' => $this->calendars->findForUser($user),
                'details'   => ShareDetail::cases(),
            ]);
        }

        $this->assertCsrf($request, self::CSRF_ID);

        $this->linkWriter->update(
            $link,
            $user,
            $request->request->getString('name'),
            $request->request->all('details'),
            $request->request->all('calendars'),
            ShareWindow::tryFrom($request->request->getString('windowMode')) ?? ShareWindow::Rolling,
            (int) $request->request->getString('rollingDays'),
            $this->date($request->request->getString('startsOn')),
            $this->date($request->request->getString('endsOn')),
        );

        $this->em->flush();

        return $this->sharingStream('calendar.share.toast.updated');
    }

    #[Route('/link/{id}/revoke', name: 'link_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function linkRevoke(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF_ID);

        $link = $this->links->findOneForUser($this->currentUser(), $id);

        if (null === $link) {
            throw $this->createNotFoundException();
        }

        $this->linkWriter->revoke($link);
        $this->em->flush();

        return $this->sharingStream('calendar.share.toast.revoked');
    }

    /**
     * A new token for a link whose URL leaked, or whose URL was lost.
     *
     * The only way to see a link's address again, which is the price of not
     * storing it. Answers with the address the way create() does, because a
     * regenerate whose result was not shown would be a link the owner could
     * neither use nor find.
     */
    #[Route('/link/{id}/regenerate', name: 'link_regenerate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function linkRegenerate(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF_ID);

        $link = $this->links->findOneForUser($this->currentUser(), $id);

        if (null === $link) {
            throw $this->createNotFoundException();
        }

        $token = $this->linkWriter->regenerate($link);

        $this->em->flush();

        return $this->sharingStream(
            'calendar.share.toast.regenerated',
            $this->generateUrl('app_shared_calendar_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    #[Route('/link/{id}/delete', name: 'link_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function linkDelete(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF_ID);

        $link = $this->links->findOneForUser($this->currentUser(), $id);

        if (null === $link) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($link);
        $this->em->flush();

        return $this->sharingStream('calendar.share.toast.deleted');
    }

    // ── Booking pages ─────────────────────────────────────────────────────────

    #[Route('/booking/new', name: 'booking_new', methods: ['GET', 'POST'])]
    public function bookingNew(Request $request): Response
    {
        $user = $this->currentUser();

        if (false === $request->isMethod('POST')) {
            return $this->renderBookingForm($user, null);
        }

        $this->assertCsrf($request, self::CSRF_ID);

        $page = new BookingPage();

        $this->fill($page, $request);

        try {
            $token = $this->pageWriter->create(
                $user,
                $page,
                $request->request->all('weekdays'),
                $request->request->all('busyCalendars'),
                $request->request->getInt('calendarId'),
            );
        } catch (\InvalidArgumentException) {
            // The one refusal this form has: no calendar that accepts writes
            // was named. Rendered at 422 so the modal stays open with what was
            // typed, rather than thrown — see BookingPageWriter for why this is
            // the one thing it will not clamp its way past.
            return $this->renderBookingForm($user, null, 'calendar.booking.error.no_calendar');
        }

        $this->em->flush();

        return $this->sharingStream(
            'calendar.booking.toast.created',
            null,
            $this->generateUrl('app_booking_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    #[Route('/booking/{id}/edit', name: 'booking_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function bookingEdit(Request $request, int $id): Response
    {
        $user = $this->currentUser();
        $page = $this->pages->findOneForUser($user, $id);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        if (false === $request->isMethod('POST')) {
            return $this->renderBookingForm($user, $page);
        }

        $this->assertCsrf($request, self::CSRF_ID);

        $this->fill($page, $request);

        try {
            $this->pageWriter->update(
                $page,
                $user,
                $request->request->all('weekdays'),
                $request->request->all('busyCalendars'),
                $request->request->getInt('calendarId'),
            );
        } catch (\InvalidArgumentException) {
            return $this->renderBookingForm($user, $page, 'calendar.booking.error.no_calendar');
        }

        $this->em->flush();

        return $this->sharingStream('calendar.booking.toast.updated');
    }

    #[Route('/booking/{id}/toggle', name: 'booking_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function bookingToggle(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF_ID);

        $page = $this->pages->findOneForUser($this->currentUser(), $id);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        $page->isEnabled = false === $page->isEnabled;

        $this->em->flush();

        return $this->sharingStream(
            true === $page->isEnabled ? 'calendar.booking.toast.enabled' : 'calendar.booking.toast.disabled',
        );
    }

    #[Route('/booking/{id}/regenerate', name: 'booking_regenerate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function bookingRegenerate(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF_ID);

        $page = $this->pages->findOneForUser($this->currentUser(), $id);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        $token = $this->pageWriter->regenerate($page);

        $this->em->flush();

        return $this->sharingStream(
            'calendar.booking.toast.regenerated',
            null,
            $this->generateUrl('app_booking_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    #[Route('/booking/{id}/delete', name: 'booking_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function bookingDelete(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF_ID);

        $page = $this->pages->findOneForUser($this->currentUser(), $id);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        $this->pageWriter->delete($page);
        $this->em->flush();

        return $this->sharingStream('calendar.booking.toast.deleted');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The posted numbers, onto the entity, before the writer is asked to make
     * sense of them.
     *
     * Assigned raw and clamped there rather than clamped here, so there is one
     * place that decides what a sane page is — the console and any future
     * importer get the same treatment as this form. getInt() is avoided for the
     * reason CalendarController names: it throws on a value it cannot convert
     * rather than answering zero, which turns a hand-edited field into a 500.
     */
    private function fill(BookingPage $page, Request $request): void
    {
        $page->name        = $request->request->getString('name');
        $page->description = $request->request->getString('description') ?: null;
        $page->timeZone    = $request->request->getString('timeZone');

        $page->startMinute   = (int) $request->request->getString('startMinute');
        $page->endMinute     = (int) $request->request->getString('endMinute');
        $page->slotMinutes   = (int) $request->request->getString('slotMinutes');
        $page->bufferMinutes = (int) $request->request->getString('bufferMinutes');
        $page->noticeMinutes = (int) $request->request->getString('noticeMinutes');
        $page->horizonDays   = (int) $request->request->getString('horizonDays');
    }

    private function renderBookingForm(User $user, ?BookingPage $page, ?string $errorKey = null): Response
    {
        return $this->render('settings/sharing/_booking_form.html.twig', [
            'page'      => $page,
            // Only calendars that accept writes: a booking written onto a
            // mirror that refuses writes back is a meeting the owner's real
            // calendar never hears about. Offered as an absence rather than a
            // disabled option, for the reason IcsController's import picker
            // gives — the option would never become selectable.
            'calendars' => $this->writableCalendars($user),
            'busySet'   => $this->calendars->findForUser($user),
            // The user's own zone as the default for a new page: a booking
            // page's hours are the owner's working day, so the zone they read
            // their own calendar in is the only sensible starting point.
            'defaultZone'    => $this->timezones->resolve($user)->getName(),
            'timezoneGroups' => TimezoneHelper::grouped(),
            'errorKey'       => $errorKey,
        ], new Response(status: null === $errorKey ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** @return list<Calendar> */
    private function writableCalendars(User $user): array
    {
        return array_values(array_filter(
            $this->calendars->findForUser($user),
            static fn (Calendar $calendar): bool => false === $calendar->isReadOnly,
        ));
    }

    /**
     * The section, redrawn, with a toast on top and — for the two actions that
     * mint one — the URL that will never be shown again.
     *
     * One method for every mutation in this controller, which is the reason the
     * two features share it: a create on either half redraws both lists, so
     * neither can go stale behind the other.
     */
    private function sharingStream(string $toastMessage, ?string $mintedLinkUrl = null, ?string $mintedBookingUrl = null): Response
    {
        $user = $this->currentUser();

        return $this->render('settings/sharing/_lists.stream.html.twig', [
            'toastMessage'     => $toastMessage,
            'shareLinks'       => $this->links->findForUser($user),
            'bookingPages'     => $this->pages->findForUser($user),
            'mintedLinkUrl'    => $mintedLinkUrl,
            'mintedBookingUrl' => $mintedBookingUrl,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * A posted Y-m-d, or null.
     *
     * Anchored at midnight in the server's zone and stored in a DATE column, so
     * the time part is discarded — the reader re-reads it as a wall-clock date
     * in the owner's zone, which is why nothing here tries to be clever about
     * offsets.
     */
    private function date(string $value): ?DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $parsed ? null : $parsed;
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
