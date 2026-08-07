<?php

declare(strict_types=1);

namespace App\Controller\Sharing;

use App\Domain\Exception\BookingRefusedException;
use App\Domain\Exception\BookingSlotTakenException;
use App\Entity\Calendar\BookingPage;
use App\Service\Appearance\PublicAppearanceResolver;
use App\Service\Calendar\Booking\BookingAvailabilityReader;
use App\Service\Calendar\Booking\BookingPageReader;
use App\Service\Calendar\Booking\BookingService;
use App\Service\Calendar\Booking\BookingWeekBuilder;
use App\Service\Calendar\Sharing\PublicLinkToken;
use App\Service\User\ClockFormatResolver;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public booking page: what is free, and taking one of it.
 *
 * ── Why it is public, and what proves the caller ──────────────────────────
 *
 * A person booking an appointment has no account here — that is the entire
 * point of publishing the page. The gate is the token in the path: 32 bytes of
 * CSPRNG, stored only as a SHA-256, switched off by the owner from settings.
 * See PublicLinkToken and the access_control entry in security.yaml, which says
 * this where somebody adding a rule will read it.
 *
 * ── The POST creates rows and sends mail, so it is bounded ────────────────
 *
 * That combination is what makes a public endpoint a spam vector, and nothing
 * about the token stops it: a token is what one legitimate visitor holds, and
 * the person abusing the page is holding the same one. So the POST is rate
 * limited per caller address through the `booking_attempt` limiter — see
 * config/packages/rate_limiter.yaml, which explains why the key is an IP here
 * and a username on the two-factor form.
 *
 * The GET is deliberately NOT limited. Reading a booking page is what it exists
 * for, and a limit on it would let one stranger take somebody's published page
 * off the internet by refreshing it.
 *
 * ── No CSRF token, and that is a decision ─────────────────────────────────
 *
 * Symfony's CSRF protection is session-backed, and this page holds no session —
 * see SharedCalendarController on why starting one on a public page is a
 * session per visitor forever. Rendering a token here would start one on every
 * GET, which is a larger and more certain cost than the attack it would
 * prevent. And that attack is thin: a cross-site POST here books an appointment
 * in a stranger's calendar under an address the attacker chose, which is
 * exactly what the form does anyway. Nothing is escalated, nothing belonging to
 * the victim is spent, and the rate limiter bounds the volume.
 *
 * ── A lost race is a redirect, not a re-render ────────────────────────────
 *
 * BookingService throws BookingSlotTakenException when the unique constraint
 * refuses the insert, and by then Doctrine has closed the EntityManager — every
 * query, including the one that would rebuild the slot list, would fail. So the
 * answer is a redirect: a fresh request, a fresh manager, and the list rebuilt
 * from what is true now. It is also the right answer on its own terms, because
 * the slot the form pointed at no longer exists and a re-render would show it
 * again.
 *
 * ── The page wears the owner's theme, and nothing else of theirs ──────────
 *
 * A booking page that looks like a default nobody chose is a page people
 * hesitate over, so it is drawn in the appearance of the account it books into.
 * The template is handed three strings rather than the User, for the reason
 * PublicAppearanceResolver states: a template that cannot reach a name cannot
 * print one.
 */
#[Route('/book', name: 'app_booking_')]
final class BookingController extends AbstractController
{
    /**
     * The query flag that turns a redirect back into a message.
     *
     * A query parameter rather than a flash, because a flash is a session and
     * this page has none. The cost is that the message survives a refresh and a
     * copied URL; that is acceptable for a sentence saying "that time has just
     * been taken", and the alternative is a cookie on a public page.
     */
    private const string TAKEN_FLAG = 'taken';

    /**
     * The parameter naming which week is on screen, in the query and in the
     * form alike.
     *
     * Carried through the POST as a hidden field for the same reason
     * `timeZone` is: a refused booking re-renders this page, and re-rendering
     * it on a different week than the one somebody was reading would move the
     * slot they had chosen out from under them.
     */
    private const string WEEK_PARAM = 'w';

    public function __construct(
        private readonly BookingPageReader           $pages,
        private readonly BookingAvailabilityReader   $availability,
        private readonly BookingWeekBuilder          $weeks,
        private readonly BookingService              $bookings,
        private readonly PublicAppearanceResolver    $appearance,
        private readonly ClockFormatResolver         $clocks,
        private readonly RateLimiterFactoryInterface $bookingAttemptLimiter,
    ) {
    }

    /**
     * The page: what this appointment is, and every slot still free.
     *
     * The visitor's zone comes from a query parameter the page's own script
     * sets, defaulting to the owner's — so the first render is correct in the
     * owner's clock and becomes correct in the visitor's without a round trip
     * that could fail. Only the DISPLAY changes: the slots themselves are
     * instants, generated in the owner's zone, and nothing about which slots
     * exist depends on who is reading.
     */
    #[Route(
        '/{token}',
        name: 'show',
        requirements: ['token' => PublicLinkToken::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function show(Request $request, string $token): Response
    {
        $page = $this->pages->resolve($token);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        return $this->renderPage($page, $token, $request, null, '' !== $request->query->getString(self::TAKEN_FLAG));
    }

    /**
     * Take a slot.
     *
     * Three outcomes and three different responses, because they mean three
     * different things to the person who pressed the button — see
     * BookingException.
     */
    #[Route(
        '/{token}',
        name: 'book',
        requirements: ['token' => PublicLinkToken::ROUTE_PATTERN],
        methods: ['POST'],
    )]
    public function book(Request $request, string $token): Response
    {
        $page = $this->pages->resolve($token);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        // Consumed before anything is read or written, so a refused attempt
        // costs one cache increment rather than an availability query and an
        // insert. TwoFactorThrottle makes the same point at the other end of
        // the application: a limiter consulted after the work is a limiter that
        // does not bound the work.
        if (false === $this->bookingAttemptLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->renderPage(
                $page,
                $token,
                $request,
                'calendar.booking.error.too_many',
                false,
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        try {
            $this->bookings->book(
                $page,
                new DateTimeImmutable(),
                $request->request->getString('slot'),
                $request->request->getString('name'),
                $request->request->getString('email'),
                $request->request->getString('note') ?: null,
                $request->request->getString('timeZone') ?: $page->timeZone,
            );
        } catch (BookingSlotTakenException) {
            // The manager is closed. Nothing may query, so the answer is a
            // redirect and the next request rebuilds the list — see the class
            // docblock.
            return $this->redirectToRoute('app_booking_show', [
                'token'          => $token,
                self::TAKEN_FLAG => '1',
            ]);
        } catch (BookingRefusedException $e) {
            // The message is already written for the person who typed it, so it
            // is shown rather than replaced by a key. 422 keeps the browser on
            // the form with what they entered still in it.
            return $this->renderPage(
                $page,
                $token,
                $request,
                null,
                false,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->getMessage(),
            );
        }

        // A redirect to a page of its own, not a rendered response, and both
        // halves of that matter.
        //
        // A page of its own because the appointment exists: leaving a filled-in
        // form on screen invites a second submit, which the unique constraint
        // would refuse correctly and inexplicably.
        //
        // A REDIRECT because Turbo is on this page — the layout loads the
        // application's own bundle for its stylesheet — and Turbo refuses to
        // render a 200 answered to a form post. It expects the redirect that
        // POST-redirect-GET produces, and without one the browser sits on the
        // form it just submitted with nothing to say why. It is also what stops
        // a refresh re-posting the booking.
        return $this->redirectToRoute('app_booking_booked', ['token' => $token]);
    }

    /**
     * "That is booked", after the fact.
     *
     * Reachable by anybody holding the page's URL, which is deliberate: it says
     * nothing about who booked or when — that is in the confirmation mail — so
     * there is nothing here for a second visitor to learn. Guarding it would
     * mean a session, and this page has none.
     */
    #[Route(
        '/{token}/booked',
        name: 'booked',
        requirements: ['token' => PublicLinkToken::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function booked(string $token): Response
    {
        $page = $this->pages->resolve($token);

        if (null === $page) {
            throw $this->createNotFoundException();
        }

        return $this->render('sharing/booked.html.twig', [
            'page'       => $page,
            'appearance' => $this->appearance->forOwner($page->usr),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The page and its slots, however it was arrived at.
     *
     * One method for the GET and for both failing POSTs, so a refused booking
     * cannot show a stale list — the slots are re-read against the instant the
     * failure happened at, which for a "no longer offered" refusal is the whole
     * point.
     */
    private function renderPage(
        BookingPage $page,
        string      $token,
        Request     $request,
        ?string     $errorKey,
        bool        $wasTaken,
        int         $status = Response::HTTP_OK,
        ?string     $error = null,
    ): Response {
        $zone = $this->displayZone($request, $page);
        $now  = new DateTimeImmutable();
        $days = $this->availability->freeSlotsByDay($page, $now, $zone);

        return $this->render('sharing/booking_page.html.twig', [
            'page'       => $page,
            'token'      => $token,
            'days'       => $days,
            'week'       => $this->weeks->build($days, $zone, $now, $this->displayWeek($request)),
            'weekParam'  => self::WEEK_PARAM,
            'zone'       => $zone->getName(),
            // Three strings, not the owner: the page wears that person's theme
            // and says nothing else about them. See PublicAppearanceResolver.
            'appearance' => $this->appearance->forOwner($page->usr),
            // The install's clock, not the owner's. Which slots exist is the
            // owner's business; how a stranger reads twelve or twenty-four is
            // not, and publishing their preference would be one more fact about
            // them that nobody asked for.
            // The full form, not the compact one: a day's pills run from
            // morning to evening in one column, and "1:00" with nothing
            // beside it is an appointment booked twelve hours out.
            'timeFormat' => $this->clocks->resolve(null)->time(),
            'errorKey'   => $errorKey,
            'error'      => $error,
            'wasTaken'   => $wasTaken,
            // Echoed back so a refused booking does not make somebody type
            // their name and address again — the one thing that reliably stops
            // people finishing a form.
            'name'       => $request->request->getString('name'),
            'email'      => $request->request->getString('email'),
            'note'       => $request->request->getString('note'),
        ], new Response(status: $status));
    }

    /**
     * Which week the slot picker is showing.
     *
     * Read from the query on a GET and from the form on a POST, the same two
     * places and in the same order as the zone above — and for the same reason:
     * a booking refused at the last moment has to come back on the week the
     * reader was looking at, or the slot they chose is no longer on screen.
     *
     * Like the zone, it is a DISPLAY parameter. BookingWeekBuilder clamps it to
     * the weeks the page offers, and the POST re-derives its slot from
     * BookingAvailabilityReader regardless — no value here can make an instant
     * bookable that was not.
     */
    private function displayWeek(Request $request): ?string
    {
        $candidate = $request->query->getString(self::WEEK_PARAM)
            ?: $request->request->getString(self::WEEK_PARAM);

        return '' === $candidate ? null : $candidate;
    }

    /**
     * The clock the slots are printed on.
     *
     * The visitor's, when they told us — the page's script puts the browser's
     * zone in the query string on first load — and the owner's otherwise. The
     * fallback is the owner's rather than UTC because a page read before the
     * script runs should show the hours the owner meant, not the same hours
     * shifted into a zone nobody is in.
     *
     * Whatever is chosen changes only what is PRINTED. The slots are instants,
     * generated against the owner's working day, and no part of which slots
     * exist depends on this.
     */
    private function displayZone(Request $request, BookingPage $page): DateTimeZone
    {
        $candidate = $request->query->getString('tz') ?: $request->request->getString('timeZone');

        try {
            return new DateTimeZone('' === $candidate ? $page->timeZone : $candidate);
        } catch (\Exception) {
            // A zone name PHP does not know, from a browser newer than this
            // install's tz database or from a hand-edited URL. The page's own
            // hours are the honest fallback; refusing to render over a display
            // preference would be absurd.
            return new DateTimeZone($page->timeZone);
        }
    }
}
