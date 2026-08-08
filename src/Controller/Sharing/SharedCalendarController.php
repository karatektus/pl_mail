<?php

declare(strict_types=1);

namespace App\Controller\Sharing;

use App\Domain\Enum\Calendar\CalendarView;
use App\Service\Appearance\PublicAppearanceResolver;
use App\Service\Calendar\Sharing\PublicLinkToken;
use App\Service\Calendar\Sharing\SharedCalendarRangeBuilder;
use App\Service\Calendar\Sharing\SharedIcsBuilder;
use App\Service\Calendar\Sharing\ShareLinkReader;
use App\Service\Calendar\Sharing\ShareLinkWriter;
use App\Service\User\ClockFormatResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Somebody else's calendar, to somebody with no account here.
 *
 * Two GETs, no session, no cookie, and the token in the path is the whole of
 * the authorisation — which is why the class is short and the reasoning is
 * long. Everything that decides what may be seen lives in ShareLinkReader; this
 * resolves, refuses, and renders.
 *
 * ── Why it is public, and what proves the caller ──────────────────────────
 *
 * The recipient of a shared calendar has no plMail account by definition — a
 * link they had to sign in to read would be a link nobody could use. The gate
 * is the token: 32 bytes of CSPRNG, unguessable inside any window, revocable by
 * the owner from settings, and stored only as a SHA-256 so a database dump is
 * not a set of working URLs. See PublicLinkToken, and see the access_control
 * entry in config/packages/security.yaml, which says the same thing where
 * somebody adding a rule will read it.
 *
 * ── One 404 for every refusal ─────────────────────────────────────────────
 *
 * An unknown token, a revoked link and a malformed path all answer 404 and all
 * answer identically. Distinguishing them would confirm which tokens had once
 * been real — the rule DevicePairingService::redeem() states — and would tell
 * somebody who had been sent a link that its owner had taken it down, which is
 * a fact about that person's availability they chose to stop publishing.
 *
 * ── No session is started ─────────────────────────────────────────────────
 *
 * Nothing here reads the session, writes a flash, or renders a CSRF token, and
 * both actions are GETs so none is needed. That is deliberate rather than
 * incidental: a Set-Cookie on a page that anybody can fetch is a session per
 * visitor, per poll of the .ics, forever — and Symfony starts one the moment
 * anything touches it, including a `csrf_token()` call in a template.
 *
 * ── What the templates may not do ─────────────────────────────────────────
 *
 * They never receive an event. SharedCalendarView carries SharedOccurrence
 * objects whose fields are already null where the link revealed nothing, so a
 * template cannot leak a title into markup, into a data attribute or into a
 * JSON payload, because it has not got one. SharedCalendarLeakTest asserts that
 * from the outside for the whole response body.
 *
 * The same rule is why the page's THEME arrives as three strings rather than as
 * the owner. It is drawn in that person's appearance — see
 * PublicAppearanceResolver, which is where the decision about what may be
 * carried over lives — and a template holding the User could print anything on
 * it. The clock format is deliberately NOT the owner's: it is the install's
 * default, because twelve-or-twenty-four is a preference of the reader's and
 * publishing the owner's would be one more fact about them nobody asked for.
 */
#[Route('/share', name: 'app_shared_calendar_')]
final class SharedCalendarController extends AbstractController
{
    /**
     * What an .ics is served as. The charset is not optional — see
     * IcsController::CONTENT_TYPE for what a browser guessing latin-1 does to a
     * downloaded calendar.
     */
    private const string CONTENT_TYPE = 'text/calendar; charset=utf-8';

    public function __construct(
        private readonly ShareLinkReader            $reader,
        private readonly ShareLinkWriter            $writer,
        private readonly SharedIcsBuilder           $ics,
        private readonly SharedCalendarRangeBuilder $ranges,
        private readonly PublicAppearanceResolver   $appearance,
        private readonly ClockFormatResolver        $clocks,
        private readonly EntityManagerInterface     $em,
    ) {
    }

    /**
     * The page a recipient opens, in whichever of the four views they asked for.
     *
     * The reader gets one instant and everything downstream is computed from
     * it, so the window, the "today" mark, the day walk and the page the view
     * opens on cannot disagree about what time it is within one request.
     *
     * ── The view and the date are in the path, and they have to be ─────────
     *
     * Exactly as the authenticated calendar carries them, and for one extra
     * reason that is decisive here: this page has no session to keep a chosen
     * view in and must not acquire one, so the URL is the only place the choice
     * can live. Every view is therefore a bookmarkable link, back works, and
     * there is no JavaScript state to lose.
     *
     * Both are display parameters and nothing more. They pick which page of the
     * published window is on screen; neither can widen the window, and
     * SharedCalendarRangeBuilder clamps a date to the window's nearest edge —
     * a reader who could step into an empty December would read it as "free in
     * December". An unparseable date is ignored rather than refused, for the same
     * reason: a public URL arrives hand-edited, and a 404 for a mistyped day is
     * a worse page than the nearest one that exists. A bad TOKEN is still a 404,
     * which is a different question — see above.
     */
    #[Route(
        '/{token}/{view}/{date}',
        name: 'show',
        requirements: [
            'token' => PublicLinkToken::ROUTE_PATTERN,
            // Spelled out rather than taken from CalendarView::routePattern():
            // a route attribute is compiled from constant expressions, which a
            // method call is not. The same duplication the authenticated
            // calendar's route carries, for the same reason.
            'view'  => 'day|week|month|agenda',
            'date'  => '\d{4}-\d{2}-\d{2}',
        ],
        defaults: ['view' => 'month', 'date' => null],
        methods: ['GET'],
    )]
    public function show(Request $request, string $token, string $view, ?string $date): Response
    {
        $link = $this->reader->resolve($token);

        if (null === $link) {
            throw $this->createNotFoundException();
        }

        $now = new DateTimeImmutable();

        $this->writer->noteView($link, $now);

        // Flushed as part of answering, because there is nothing else in this
        // unit of work and the write is one column on one row. It is the only
        // write a public GET makes and it records nothing about the visitor —
        // see CalendarShareLink::$lastViewedAt on why it is a timestamp rather
        // than a counter or an address.
        $this->em->flush();

        $shared = $this->reader->read($link, $now);

        return $this->render('sharing/shared_calendar.html.twig', [
            // `shared`, not `view`: the shells this page renders through are the
            // authenticated calendar's, and there `view` is the CalendarView
            // enum. Two meanings for one name across four templates is how a
            // switcher ends up highlighting whatever `view.value` happened to
            // answer.
            'shared'     => $shared,
            'range'      => $this->ranges->build($shared, CalendarView::from($view), $this->anchor($request, $date), $now),
            'token'      => $token,
            'icsPath'    => $this->generateUrl('app_shared_calendar_ics', ['token' => $token]),
            'appearance' => $this->appearance->forOwner($link->usr),
            // Three shapes, the same vocabulary the `clock` global gives the
            // authenticated app — and passed explicitly rather than read from
            // that global, because it resolves the SIGNED-IN user's clock and
            // reaching for one here is reaching for a session. Compact inside a
            // grid cell, where a meridiem costs a fifth of the width; the full
            // form in an agenda row, where "10:00–11:00" with no am on it is a
            // meeting somebody misses by twelve hours; and the hour form for a
            // time-grid's gutter, where ":00" printed twenty-four times is
            // noise.
            'chipTimeFormat' => $this->clocks->resolve(null)->timeCompact(),
            'rowTimeFormat'  => $this->clocks->resolve(null)->time(),
            'hourFormat'     => $this->clocks->resolve(null)->hour(),
        ]);
    }

    /**
     * The same window as a file, for a calendar client to subscribe to.
     *
     * A separate route rather than a format on the first, so the HTML page can
     * link to it and so a client polling it does not move $lastViewedAt — which
     * would turn "when did somebody last look at this" into "is a calendar app
     * still subscribed". ShareLinkWriter::noteView() says the same at the other
     * end.
     *
     * Answered whole rather than streamed, unlike IcsController's calendar
     * export: the reader caps a window at a couple of thousand entries of four
     * lines each, so there is no unbounded file here to stream.
     */
    #[Route(
        '/{token}/calendar.ics',
        name: 'ics',
        requirements: ['token' => PublicLinkToken::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function ics(string $token): Response
    {
        $link = $this->reader->resolve($token);

        if (null === $link) {
            throw $this->createNotFoundException();
        }

        $body = $this->ics->build($this->reader->read($link, new DateTimeImmutable()));

        return new Response($body, Response::HTTP_OK, [
            'Content-Type'        => self::CONTENT_TYPE,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->ics->fileName(),
            ),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The day the page opens on: the one in the path, or the one an older link
     * asked for by month.
     *
     * `?month=YYYY-MM` is what this page used before it had views, and it is
     * still read because the URLs carrying it are in other people's mail. A month
     * names a page rather than a day, so the first of it is the day that page
     * opens on — and the builder normalises a month anchor to that same first day
     * anyway. Anything unparseable falls through to null and the default, which
     * is what a hand-edited public URL should get.
     */
    private function anchor(Request $request, ?string $date): ?string
    {
        if (null !== $date) {
            return $date;
        }

        $month = $request->query->getString('month');

        return 1 === preg_match('/^\d{4}-\d{2}$/', $month) ? $month . '-01' : null;
    }
}
