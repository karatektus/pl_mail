<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Calendar\IcsImportResult;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\IntegrationException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Calendar\Ics\IcsExporter;
use App\Service\Calendar\Ics\IcsFeedConnector;
use App\Service\Calendar\Ics\IcsImporter;
use App\Service\Calendar\Subscription\CalendarSourceLister;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * iCalendar as a thing a person handles: a file to download, a file to upload,
 * an address to subscribe to.
 *
 * Three actions that look unrelated and are one feature. All three are the same
 * document format arriving or leaving by a different door, and they share every
 * decision that matters underneath — IcsDocumentReader splits a file into
 * meetings, CalDavEventConverter maps a meeting both ways, and
 * RecurrenceRuleConverter carries the repeats. That is why they are one
 * controller: three controllers would be three places to notice when the
 * mapping grows a case, and a round trip that stopped round-tripping would show
 * up only in whichever of them was not updated.
 *
 * ── Where the responses go ────────────────────────────────────────────────
 *
 * The two downloads answer with a file and nothing else. The two forms are
 * modals opened from Settings → Calendars and answer with the same Turbo Stream
 * every other calendar mutation there answers with, so the list behind the
 * dialog refreshes and a toast says what happened. That is deliberate reuse
 * rather than convenience: subscribing to a feed adds a connection *and* a
 * calendar, and a response that redrew only one of them would leave the other
 * invisible until the page was reloaded — which is exactly the bug
 * CalendarSettingsController::calendarListStream() was written to fix.
 *
 * ── The forms are hand-written ────────────────────────────────────────────
 *
 * No FormType for either, following calendar/_subscribe.html.twig. The import
 * has no entity behind it at all — an upload and the id of a calendar to put it
 * on — and the subscribe form has two fields whose entity is created by the
 * service rather than bound by the form, because a submitted name has to be
 * made unique against what the user already has before an Integration can carry
 * it. A form type mapping fields it must then ignore is worse than no form
 * type.
 *
 * ── Read-only is checked twice, and neither check is redundant ────────────
 *
 * The import picker lists only calendars that accept writes, and IcsImporter
 * refuses a read-only one outright. The first is what a person meets; the
 * second is what a crafted POST meets. Dropping either leaves a way to put rows
 * on a mirror that can never send them anywhere — see IcsImporter.
 */
#[Route('/calendar/ics', name: 'app_calendar_ics_')]
#[IsGranted('IS_AUTHENTICATED')]
final class IcsController extends AbstractController
{
    use ChecksCsrf;

    /**
     * What an .ics is served as.
     *
     * The charset is not optional. iCalendar is UTF-8 by default under RFC 5545
     * §6, and a browser that guesses latin-1 for a downloaded file turns every
     * umlaut in a German holiday calendar into two characters — which then
     * re-imports that way, permanently.
     */
    private const string CONTENT_TYPE = 'text/calendar; charset=utf-8';

    /**
     * How large an uploaded .ics may be.
     *
     * Four mebibytes, which is half what IcsFeedClient will download and for a
     * different reason: a feed is fetched by a worker with a job's worth of
     * memory, an upload is parsed inside a web request that also has to answer
     * within the request timeout. The number is a bound on the parse, because
     * sabre has no incremental component API and the document tree exists
     * whole — see IcsDocumentReader, which says so rather than pretending
     * otherwise.
     */
    private const int MAX_UPLOAD_BYTES = 4194304;

    public function __construct(
        private readonly CalendarRepository      $calendars,
        private readonly CalendarEventRepository $events,
        private readonly IcsExporter             $exporter,
        private readonly IcsImporter             $importer,
        private readonly IcsFeedConnector        $feeds,
        private readonly CalendarSourceLister    $sources,
        private readonly TranslatorInterface     $translator,
        private readonly MessageBusInterface     $bus,
        private readonly EntityManagerInterface  $em,
    ) {
    }

    /**
     * One event as a file.
     *
     * A whole series, not the occurrence a chip was clicked on — see
     * IcsExporter::one(). Answered as an ordinary Response rather than a
     * streamed one: a single meeting is a few hundred bytes and streaming it
     * would be machinery with nothing to carry.
     */
    #[Route('/event/{id}', name: 'event', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function event(CalendarEvent $event): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $event);

        return $this->fileResponse(
            $this->exporter->one($event),
            // The event's own title, falling back to a word rather than to
            // the empty string a titleless row would give: fileNameFor()
            // reduces that to "calendar.ics", which names the wrong thing.
            $this->exporter->fileNameFor('' === (string) $event->title ? 'event' : (string) $event->title),
        );
    }

    /**
     * A whole calendar as a file, streamed.
     *
     * The events are read one at a time and the file is yielded one meeting at
     * a time, so a calendar with ten years on it costs one meeting of memory
     * rather than ten years of it — see CalendarEventRepository::iterateForCalendar()
     * and IcsExporter::document(), which are two halves of the same decision
     * and neither of which is much use alone.
     *
     * No Content-Length, which is the price: the size is not known until the
     * last event has been formatted, so the browser shows an indeterminate
     * progress bar. That is a better trade than holding the whole calendar in
     * memory to be able to count it.
     */
    #[Route('/calendar/{id}', name: 'calendar', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function calendar(Calendar $calendar): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $calendar);

        $chunks = $this->exporter->document($calendar, $this->events->iterateForCalendar($calendar));

        $response = new StreamedResponse(static function () use ($chunks): void {
            foreach ($chunks as $chunk) {
                echo $chunk;

                // Pushed out per meeting rather than left to PHP's buffer,
                // which is what makes this a stream rather than a slower way of
                // building the same string: without it the whole file
                // accumulates in the output buffer and the memory saved by
                // iterating the rows is spent again on the way out.
                flush();
            }
        });

        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set(
            'Content-Disposition',
            $this->disposition($this->exporter->fileNameFor($calendar->name)),
        );

        return $response;
    }

    /**
     * Put an uploaded .ics onto a calendar the user picks.
     *
     * GET draws the form, POST performs it, on one route like every other modal
     * form in the calendar settings.
     */
    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $user = $this->currentUser();

        if (false === $request->isMethod('POST')) {
            return $this->renderImportForm($user);
        }

        $this->assertCsrf($request, 'calendar-ics-import');

        $calendar = $this->calendars->findOneForUser($user, $request->request->getInt('calendarId'));
        $upload   = $request->files->get('file');
        $bytes    = $this->uploadedBytes($upload);

        if (null === $calendar) {
            return $this->renderImportForm($user, 'calendar.ics.error.no_calendar', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null === $bytes) {
            return $this->renderImportForm($user, 'calendar.ics.error.no_file', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->importer->import($calendar, $user, $bytes);
        } catch (CalendarSyncException $e) {
            // Rendered rather than thrown, at 422 so the modal stays open. The
            // message names what actually went wrong — "this is not a calendar
            // file" — and the person reading it is holding the file, which is
            // the only place the mistake can be corrected.
            return $this->renderImportForm($user, null, Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        $this->em->flush();

        // After the flush, so the worker reads committed rows. Silent unless
        // the calendar mirrors something writable, which is the only case where
        // an import owes anybody a push.
        if (true === $result->changedAnything() && true === $calendar->isSynced()) {
            $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));
        }

        return $this->calendarListStream(...$this->importToast($result));
    }

    /**
     * Subscribe to a calendar published at an address.
     *
     * One field that matters and one that does not: the address, and what to
     * call it. There is no "which calendars?" step because a feed is one
     * calendar — CalendarSubscriber::subscribeAll() says why that is a
     * different method rather than a list of one tick box.
     */
    #[Route('/subscribe', name: 'subscribe', methods: ['GET', 'POST'])]
    public function subscribe(Request $request): Response
    {
        if (false === $request->isMethod('POST')) {
            return $this->renderSubscribeForm();
        }

        $this->assertCsrf($request, 'calendar-ics-subscribe');

        $url = trim($request->request->getString('url'));

        if ('' === $url) {
            return $this->renderSubscribeForm('calendar.ics.error.no_url', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $error = $this->feeds->connect(
                $this->currentUser(),
                $url,
                $request->request->getString('name'),
            );
        } catch (IntegrationException $e) {
            // A refusal about the field — a malformed address, a scheme this
            // cannot fetch, a host inside the deployment's own network. Its
            // message is already written for a person by
            // IntegrationUrlValidator, so it is shown rather than replaced.
            return $this->renderSubscribeForm(null, Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage(), $url);
        }

        if (null !== $error) {
            return $this->renderSubscribeForm(null, Response::HTTP_UNPROCESSABLE_ENTITY, $error, $url);
        }

        return $this->calendarListStream('calendar.ics.toast.subscribed');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The uploaded file's bytes, or null when there is nothing usable.
     *
     * Read through the temporary path rather than through UploadedFile::getContent(),
     * which throws for a file the upload itself rejected — a size limit at the
     * PHP or web-server level arrives here as an UploadedFile that exists and
     * has no content, and a 500 is the wrong answer to "your file was too big".
     */
    private function uploadedBytes(mixed $upload): ?string
    {
        if (false === $upload instanceof UploadedFile) {
            return null;
        }

        if (false === $upload->isValid() || $upload->getSize() > self::MAX_UPLOAD_BYTES) {
            return null;
        }

        $bytes = @file_get_contents($upload->getPathname());

        return false === $bytes || '' === $bytes ? null : $bytes;
    }

    /**
     * The toast for one import, and the numbers in it.
     *
     * Three outcomes, because two of them read as failures if they are not
     * named. A file every one of whose events is already on the calendar
     * changes nothing, and reporting that as "0 imported" is
     * indistinguishable from an import that did not work — which is the normal
     * result of importing an export, or of importing the same file twice.
     *
     * @return array{0: string, 1: array<string,int|string>}
     */
    private function importToast(IcsImportResult $result): array
    {
        if (0 === $result->read()) {
            return ['calendar.ics.toast.import_empty', []];
        }

        if (false === $result->changedAnything()) {
            return ['calendar.ics.toast.import_nothing_new', []];
        }

        return ['calendar.ics.toast.imported', [
            '%added%'   => $result->imported,
            '%updated%' => $result->updated,
            // The two "not written" reasons are one number on screen. They are
            // different facts (already on another calendar; nothing in the
            // component to draw) and a person acting on the toast does the same
            // thing about both, which is nothing.
            '%skipped%' => $result->alreadyElsewhere + $result->skipped,
        ]];
    }

    private function renderImportForm(
        User    $user,
        ?string $errorKey = null,
        int     $status = Response::HTTP_OK,
        ?string $error = null,
    ): Response {
        return $this->render('calendar/_ics_import.html.twig', [
            'calendars' => $this->writableCalendars($user),
            'error'     => null !== $errorKey ? $this->translatedKey($errorKey) : $error,
            'maxBytes'  => self::MAX_UPLOAD_BYTES,
        ], new Response(status: $status));
    }

    private function renderSubscribeForm(
        ?string $errorKey = null,
        int     $status = Response::HTTP_OK,
        ?string $error = null,
        string  $url = '',
    ): Response {
        return $this->render('calendar/_ics_subscribe.html.twig', [
            'error' => null !== $errorKey ? $this->translatedKey($errorKey) : $error,
            'url'   => $url,
        ], new Response(status: $status));
    }

    /**
     * The calendars an import may be put on.
     *
     * A read-only mirror is dropped rather than rendered disabled: a disabled
     * option in a picker is a promise that it might become selectable, and this
     * one never will — the calendar is a copy of somewhere that does not accept
     * writes, for as long as it is subscribed.
     *
     * @return list<Calendar>
     */
    private function writableCalendars(User $user): array
    {
        return array_values(array_filter(
            $this->calendars->findForUser($user),
            static fn (Calendar $calendar): bool => false === $calendar->isReadOnly,
        ));
    }

    /**
     * The settings list, redrawn, with a toast on top.
     *
     * The same response CalendarSettingsController answers every calendar
     * mutation with, and rendered here rather than delegated to it because a
     * controller is not a service — what it renders belongs to the template,
     * and the template is the shared thing.
     *
     * @param array<string,int|string> $toastParameters
     */
    private function calendarListStream(string $toastMessage, array $toastParameters = []): Response
    {
        $user = $this->currentUser();

        return $this->render('calendar/_lists.stream.html.twig', [
            'toastMessage'        => $toastMessage,
            'toastParameters'     => $toastParameters,
            'calendars'           => $this->calendars->findForUser($user),
            'calendarAccounts'    => $this->sources->accountsFor($user),
            'calendarConnections' => $this->sources->connectionsFor($user),
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * A message key rendered as a message, for the failures this controller
     * names itself rather than catching.
     *
     * The two forms take an `error` string because the interesting failures
     * arrive as exception messages already written for a person; these two are
     * about the form itself and so live in the translation catalogue like every
     * other piece of UI copy.
     */
    private function translatedKey(string $key): string
    {
        return $this->translator->trans($key);
    }

    private function fileResponse(string $body, string $fileName): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type'        => self::CONTENT_TYPE,
            'Content-Disposition' => $this->disposition($fileName),
        ]);
    }

    /**
     * Content-Disposition, with the RFC 5987 form beside the plain one.
     *
     * HeaderUtils::makeDisposition() writes both — an ASCII fallback and a
     * UTF-8 `filename*` — which matters because a calendar called "Urlaub &
     * Reisen" reduces to an ASCII name that has lost the distinction between
     * two of the user's calendars. IcsExporter::fileNameFor() supplies the
     * fallback; this is what lets a modern browser ignore it.
     */
    private function disposition(string $fileName): string
    {
        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, $fileName);
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
