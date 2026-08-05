<?php

declare(strict_types=1);

namespace App\Controller\Sharing;

use App\Service\Calendar\Sharing\PublicLinkToken;
use App\Service\Calendar\Sharing\SharedIcsBuilder;
use App\Service\Calendar\Sharing\ShareLinkReader;
use App\Service\Calendar\Sharing\ShareLinkWriter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
        private readonly ShareLinkReader        $reader,
        private readonly ShareLinkWriter        $writer,
        private readonly SharedIcsBuilder       $ics,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The page a recipient opens.
     *
     * The reader gets one instant and everything downstream is computed from
     * it, so the window, the "today" heading and the day walk cannot disagree
     * about what time it is within one request.
     */
    #[Route(
        '/{token}',
        name: 'show',
        requirements: ['token' => PublicLinkToken::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function show(string $token): Response
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

        return $this->render('sharing/shared_calendar.html.twig', [
            'view'    => $this->reader->read($link, $now),
            'icsPath' => $this->generateUrl('app_shared_calendar_ics', ['token' => $token]),
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
}
