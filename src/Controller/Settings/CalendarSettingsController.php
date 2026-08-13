<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Integration\ServiceKind;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Form\CalDavConnectType;
use App\Form\CalendarType;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\AccountRepository;
use App\Service\Calendar\Subscription\CalDavConnector;
use App\Service\Calendar\Subscription\CalendarDiscoverer;
use App\Service\Calendar\Subscription\CalendarSourceLister;
use App\Service\Calendar\Subscription\CalendarSubscriber;
use App\Service\Push\PushTeardown;
use App\Service\User\UserTimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
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
 *
 * The second half of this controller is subscribing: listing what a mail
 * account or a CalDAV connection offers and mirroring what the user ticks. Two
 * things about it are worth knowing before changing anything here.
 *
 *   **Discovery is allowed to fail, and the failure is the answer.** Google's
 *   consent screen lets a user untick the calendar scope while granting the
 *   mail one, so an account that syncs mail perfectly can answer every calendar
 *   call with a refusal. CalendarSyncException subclasses carry a sentence
 *   written for a person — "Reconnect the account and allow calendar access" —
 *   and this renders it in place of the list. An uncaught one would be a 500 on
 *   a screen whose entire purpose is to explain why calendars are missing.
 *
 *   **Nothing the browser posts describes a calendar.** The subscribe form
 *   carries remote ids and nothing else; every other property is re-read from
 *   the remote inside CalendarSubscriber. See its docblock for why isReadOnly
 *   in particular must never come from a form field.
 */
#[Route('/settings/calendars', name: 'app_settings_calendar_')]
#[IsGranted('IS_AUTHENTICATED')]
final class CalendarSettingsController extends AbstractController
{
    public function __construct(
        private readonly CalendarRepository     $calendars,
        private readonly AccountRepository      $accounts,
        private readonly IntegrationRepository  $integrations,
        private readonly CalendarDiscoverer     $discoverer,
        private readonly CalendarSubscriber     $subscriber,
        private readonly CalendarSourceLister   $sources,
        private readonly CalDavConnector        $caldav,
        private readonly UserTimezoneResolver   $timezones,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
        private readonly PushTeardown           $pushTeardown,
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

        // Before the remove, because the channel id needed to call the
        // registration off lives on the row being removed. Best-effort: a
        // provider that cannot be reached must not stop somebody deleting their
        // own calendar. See PushTeardown.
        $this->pushTeardown->forCalendar($calendar);

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

    // ── Subscribing ───────────────────────────────────────────────────────────

    /**
     * What one account or connection offers, with the ones already mirrored
     * ticked.
     *
     * GET and POST on one route, like every other modal form here: the GET
     * draws the list, the POST applies it, and both need the same discovery
     * because the POST re-reads every calendar's properties from the remote
     * rather than trusting the form.
     */
    #[Route('/subscribe/{kind}/{id}', name: 'subscribe', requirements: ['kind' => 'account|integration', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function subscribe(Request $request, string $kind, int $id): Response
    {
        $source = $this->sourceFor($kind, $id);

        if (false === $request->isMethod('POST')) {
            return $this->renderSubscribeList($source, $kind, $id);
        }

        $this->assertCsrf($request, 'calendar-subscribe' . $kind . $id);

        try {
            $change = $this->subscriber->apply($source, $this->tickedRemoteIds($request));
        } catch (CalendarSyncException $e) {
            // Rendered rather than thrown, and at 422 so the modal stays open:
            // a listing that failed halfway would look like "these calendars
            // are gone", so the subscriber writes nothing and the user sees
            // why. The modal closes on any successful submit.
            return $this->renderSubscribeList($source, $kind, $id, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (true === $change->isEmpty()) {
            return $this->calendarListStream('calendar.toast.subscriptions_unchanged');
        }

        return $this->calendarListStream(
            $change->kept > 0 ? 'calendar.toast.subscriptions_saved_kept' : 'calendar.toast.subscriptions_saved',
            ['%count%' => $change->kept],
        );
    }

    /**
     * Connect a CalDAV server: address, then credential, then the same
     * discover-and-tick list.
     *
     * The success response is the subscribe list rather than a stream, and the
     * form carries data-ui--modal-keep-open so the dialog survives its own
     * submit — connecting a server nobody can list calendars from is half a
     * feature, and making the user find the new connection in the list behind
     * the modal to press a second button is the other half done badly.
     */
    #[Route('/caldav/connect', name: 'caldav_connect', methods: ['GET', 'POST'])]
    public function connectCalDav(Request $request): Response
    {
        $user = $this->currentUser();

        $integration = new Integration($user, Provider::CalDav, Provider::CalDav->label());
        $integration->baseUrl = $this->caldav->suggestedAddress($user);

        $form = $this->createForm(CalDavConnectType::class, $integration, [
            'action'           => $this->generateUrl('app_settings_calendar_caldav_connect'),
            'lending_accounts' => $this->caldav->lendingAccounts($user),
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $error = $this->caldav->connect($integration, $form);

            if (null === $error) {
                return $this->renderSubscribeList(
                    CalendarSource::ofIntegration($integration),
                    'integration',
                    (int) $integration->id,
                );
            }

            return $this->renderCalDavForm($form, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderCalDavForm(
            $form,
            null,
            true === $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /**
     * Remove a calendar connection and everything mirrored from it.
     *
     * Offered here as well as in Settings → Integrations because this is where
     * it was made: a screen that can connect a server and not disconnect one is
     * a screen that sends people looking for a different screen.
     *
     * Nothing is discovered on the way out — see
     * CalendarSubscriber::unsubscribeAll(). The connection somebody most wants
     * gone is the one that can no longer be listed.
     */
    #[Route('/caldav/{id}/disconnect', name: 'caldav_disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disconnectCalDav(Request $request, int $id): Response
    {
        $source = $this->sourceFor('integration', $id);
        $this->assertCsrf($request, 'calendar-disconnect' . $id);

        $integration = $source->integration;

        if (null === $integration) {
            throw $this->createAccessDeniedException();
        }

        $change = $this->subscriber->unsubscribeAll($integration);

        $this->em->remove($integration);
        $this->em->flush();

        return $this->calendarListStream(
            $change->kept > 0 ? 'calendar.toast.disconnected_kept' : 'calendar.toast.disconnected',
            ['%count%' => $change->kept],
        );
    }

    /**
     * Ask for one calendar to be brought up to date now.
     *
     * Dispatches rather than syncing inline: the sweep's handler is the only
     * thing that may talk to a remote calendar, so doing it here would be a
     * second implementation of the same operation, and a slow provider would
     * hold an HTTP request open until it timed out.
     */
    #[Route('/{id}/sync', name: 'sync', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sync(Request $request, Calendar $calendar): Response
    {
        $this->assertOwned($calendar);
        $this->assertCsrf($request, 'calendar-sync' . $calendar->id);

        if (false === $calendar->isSynced()) {
            throw $this->createAccessDeniedException();
        }

        $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));

        return $this->calendarListStream('calendar.toast.sync_queued');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The source behind a `{kind}/{id}` pair, refused unless it is this user's.
     *
     * Ownership is asserted here rather than in each action, so no route that
     * takes a source can forget it — the same rule assertOwnership() keeps for
     * calendars.
     */
    private function sourceFor(string $kind, int $id): CalendarSource
    {
        $user = $this->currentUser();

        if ('account' === $kind) {
            $account = $this->accounts->find($id);

            if (null === $account || $account->usr !== $user) {
                throw $this->createAccessDeniedException();
            }

            return CalendarSource::ofAccount($account);
        }

        $integration = $this->integrations->find($id);

        // ServiceKind, not the CalDav case: a file connection has no calendars
        // and no driver, and answering "not yours" for one is more honest than
        // letting it reach a registry that would say the same thing in a 500.
        if (null === $integration
            || $integration->usr !== $user
            || ServiceKind::Calendar !== $integration->provider->kind()) {
            throw $this->createAccessDeniedException();
        }

        return CalendarSource::ofIntegration($integration);
    }

    /**
     * The remote ids that were ticked.
     *
     * Filtered to strings and re-packed, because this goes into a query
     * parameter binding downstream and a nested array arriving from the
     * request would be a type error rather than an empty selection.
     *
     * @return list<string>
     */
    private function tickedRemoteIds(Request $request): array
    {
        /** @var array<mixed> $ticked */
        $ticked = $request->request->all('remoteIds');

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $ticked),
            static fn (string $value): bool => '' !== $value,
        ));
    }

    /**
     * The subscribe list, or the reason there is not one.
     *
     * Both come back through this one method so the failure renders inside the
     * modal frame the list would have, rather than as a bare error page Turbo
     * has nowhere to put.
     */
    private function renderSubscribeList(
        CalendarSource $source,
        string $kind,
        int $id,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $subscriptions = [];

        if (null === $error) {
            try {
                $subscriptions = $this->discoverer->discover($source);
            } catch (CalendarSyncException $e) {
                // The message is written to be read by a person and never
                // carries a credential — the same contract Calendar::$lastSyncError
                // holds. See the class docblock for why this is caught at all.
                $error  = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('calendar/_subscribe.html.twig', [
            'subscriptions' => $subscriptions,
            'sourceKind'    => $kind,
            'sourceId'      => $id,
            'sourceLabel'   => $this->labelFor($source),
            'error'         => $error,
        ], new Response(status: $status));
    }

    private function renderCalDavForm(FormInterface $form, ?string $error, int $status): Response
    {
        return $this->render('calendar/_caldav_form.html.twig', [
            'form'  => $form,
            'error' => $error,
        ], new Response(status: $status));
    }

    /** What to call the thing whose calendars are being listed. */
    private function labelFor(CalendarSource $source): string
    {
        if (null !== $source->account) {
            return (string) $source->account->displayAddress;
        }

        return null === $source->integration ? '' : $source->integration->name;
    }

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
     *
     * The connectable sources go out with it because connecting a CalDAV server
     * adds one: the list has to grow a row the moment the connection exists,
     * and re-rendering only the calendars would leave the new server invisible
     * until the page was reloaded.
     *
     * @param array<string,int|string> $toastParameters
     */
    private function calendarListStream(string $toastMessage, array $toastParameters = []): Response
    {
        $user = $this->currentUser();

        return $this->render('calendar/_lists.stream.html.twig', [
            'toastMessage'    => $toastMessage,
            'toastParameters' => $toastParameters,
            'calendars'       => $this->calendars->findForUser($user),
            ...$this->sourceData($user),
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * What the settings screen needs to draw the "where calendars come from"
     * block. SettingsController builds the first render from the same lister,
     * rather than from a second rule about which accounts qualify — two
     * spellings of that question is how one of them starts offering a button
     * that answers "no calendar service is configured for this account".
     *
     * @return array<string,mixed>
     */
    private function sourceData(User $user): array
    {
        return [
            'calendarAccounts'    => $this->sources->accountsFor($user),
            'calendarConnections' => $this->sources->connectionsFor($user),
        ];
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
