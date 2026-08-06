<?php

declare(strict_types=1);

namespace App\Jmap\Method\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Calendar\CalendarState;
use App\Jmap\Calendar\JmapEventWriter;
use App\Jmap\Calendar\OccurrenceId;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "CalendarEvent/set": create, update and destroy events.
 *
 * Every write goes through CalendarEventWriter, the one place an event is
 * written — reading a JMAP object off the wire is JmapEventWriter's, and what
 * an event IS stays where the web editor and the sync engine already find it.
 * Nothing here assigns a column.
 *
 * **A synced event written here is marked for push, and that is a decision.**
 * CalendarEventWriter::markLocallyChanged() is deliberately not called by
 * write() itself, because write() is also how the sync engine applies what it
 * has just *read* from a remote — marking there would push the remote's own
 * data straight back at it. So the marking belongs to whatever made the change,
 * and a JMAP /set is a person making a change, exactly as the web editor is. Not
 * marking would mean an event edited on a phone never reaches Google or
 * Microsoft and is silently reverted by the next pull: the edit would be visible
 * in plMail for fifteen minutes and then gone, which is the precise failure
 * SyncState exists to prevent. The same seam as CalendarController::eventSave(),
 * because a second push path is how the two would drift.
 *
 * A sync is asked for immediately afterwards for the same reason that
 * controller asks: fifteen minutes is the right cadence for noticing somebody
 * else's change and the wrong one for watching your own arrive. At most one
 * message per calendar, dispatched after the flush so the worker reads a
 * committed row.
 *
 * `ifInState` is refused rather than honoured. It asks the server to promise
 * that nothing has changed since a token was issued, and the calendar state is
 * fixed (see CalendarState) — so honouring it would always answer "nothing has
 * changed", which is not something this server knows. A guard that cannot fail
 * is worse than no guard, because a client would rely on it.
 */
final class CalendarEventSetMethod implements JmapMethod
{
    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarEventRepository $events,
        private readonly JmapEventWriter $jmapWriter,
        private readonly CalendarEventWriter $writer,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'CalendarEvent/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        if (true === array_key_exists('ifInState', $arguments) && null !== $arguments['ifInState']) {
            throw new MethodException('invalidArguments', 'ifInState cannot be honoured: the calendar state is fixed, so it could only ever answer "nothing has changed".');
        }

        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];
        $destroyed = [];
        $notDestroyed = [];

        /** @var list<Calendar> $touched */
        $touched = [];

        $this->applyCreates($context, $arguments['create'] ?? null, $created, $notCreated, $touched);
        $this->applyUpdates($context, $arguments['update'] ?? null, $updated, $notUpdated, $touched);
        $this->applyDestroys($context, $arguments['destroy'] ?? null, $destroyed, $notDestroyed, $touched);

        $this->entityManager->flush();

        $this->dispatchSync($touched);

        return [
            'accountId' => (string) $account->id,
            'oldState' => CalendarState::FIXED,
            'newState' => CalendarState::FIXED,
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => $destroyed,
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $created
     * @param array<string,mixed> $notCreated
     * @param list<Calendar>      $touched
     */
    private function applyCreates(JmapContext $context, mixed $create, array &$created, array &$notCreated, array &$touched): void
    {
        if (null === $create) {
            return;
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        foreach ($create as $creationId => $properties) {
            $creationId = (string) $creationId;

            if (false === is_array($properties)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];

                continue;
            }

            try {
                $event = $this->jmapWriter->create($context->user, $properties);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();

                continue;
            }

            // A create that has never been at the remote is a POST, not a PUT,
            // which markLocallyChanged() cannot tell from an event in step with
            // it — see CalendarEventWriter. A no-op on a calendar that mirrors
            // nothing, so there is no branch here.
            $this->writer->markLocallyCreated($event);
            $this->remember($touched, $event);

            $id = (string) $event->id;

            // Lets a later call in the same request refer to "#creationId".
            $context->recordCreatedId($creationId, $id);

            // Server-set properties the client could not know; the spec
            // requires them back on the created object. uid is here because a
            // client that did not supply one gets the minted identity every
            // other calendar will match this event on.
            $created[$creationId] = [
                'id' => $id,
                'uid' => $event->uid,
                'calendarId' => null === $event->calendar ? null : (string) $event->calendar->id,
                'isRecurring' => $event->isRecurring,
                'sequence' => $event->sequence,
            ];
        }
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     * @param list<Calendar>      $touched
     */
    private function applyUpdates(JmapContext $context, mixed $update, array &$updated, array &$notUpdated, array &$touched): void
    {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;
            $resolved = $context->resolveId($id) ?? $id;

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];

                continue;
            }

            $refusal = $this->refuseInstanceId($resolved);

            if (null !== $refusal) {
                $notUpdated[$id] = $refusal;

                continue;
            }

            $event = $this->findOne($context, $resolved);

            if (null === $event) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such CalendarEvent.'];

                continue;
            }

            // Where the event is NOW, not where the patch may be moving it: a
            // read-only calendar refuses to give an event up as firmly as it
            // refuses to take one.
            $from = $event->calendar;

            try {
                $this->jmapWriter->update($context->user, $event, $patch);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();

                continue;
            }

            // A person edited this, so the reconciler must stop overwriting it
            // from later mail about the same booking — the same call the web
            // editor makes, and a no-op on anything not extracted.
            $this->writer->markUserEdited($event);
            $this->writer->markLocallyChanged($event);

            $this->remember($touched, $event);

            if (null !== $from && $from !== $event->calendar) {
                // A move owes the calendar it left a sync too, or the remote
                // goes on holding an event that is no longer there.
                $touched[] = $from;
            }

            // null = "no properties changed beyond what the client asked for".
            $updated[$id] = null;
        }
    }

    /**
     * @param list<string>        $destroyed
     * @param array<string,mixed> $notDestroyed
     * @param list<Calendar>      $touched
     */
    private function applyDestroys(JmapContext $context, mixed $destroy, array &$destroyed, array &$notDestroyed, array &$touched): void
    {
        if (null === $destroy) {
            return;
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id = (string) $id;
            $resolved = $context->resolveId($id) ?? $id;

            $refusal = $this->refuseInstanceId($resolved);

            if (null !== $refusal) {
                $notDestroyed[$id] = $refusal;

                continue;
            }

            $event = $this->findOne($context, $resolved);

            if (null === $event) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such CalendarEvent.'];

                continue;
            }

            $calendar = $event->calendar;

            if (null !== $calendar && true === $calendar->isReadOnly) {
                $notDestroyed[$id] = [
                    'type' => 'forbidden',
                    'description' => sprintf('Calendar "%s" is read-only; it mirrors somewhere that does not accept writes.', (string) $calendar->id),
                ];

                continue;
            }

            $this->remember($touched, $event);

            // A synced event is not removed: the row is the only record that
            // the remote still holds a copy, so it survives — occurrences
            // dropped, which is what makes the deletion look immediate — until
            // the remote has been told. Same call the web editor makes.
            if (true === $this->writer->markLocallyDeleted($event)) {
                $this->entityManager->remove($event);
            }

            $destroyed[] = $id;
        }
    }

    /**
     * Scoped to the owner, so somebody else's event is notFound rather than
     * forbidden — the two are distinguishable to a client, and only one of them
     * confirms that the id exists.
     */
    private function findOne(JmapContext $context, string $id): ?CalendarEvent
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        return $this->events->findOneForUser($context->user, (int) $id);
    }

    /**
     * The refusal an instance id gets, or null for every other id.
     *
     * `CalendarEvent/query` with `expandRecurrences` hands out ids that name one
     * occurrence of a series (OccurrenceId), and this method cannot write one:
     * changing a single instance is a `recurrenceOverrides` patch on the series,
     * filed under that instance's original start, and there is nothing here that
     * turns "update id 42_20260304T090000Z" into that patch.
     *
     * Said out loud rather than left to fall through findOne(), which would
     * answer notFound. That is the wrong answer twice over: the id does exist,
     * this server minted it, and a client told "no such event" would go looking
     * for a bug in its own id handling instead of reading the sentence that says
     * what to send instead. The draft expects `/set` to resolve these ids
     * itself; until it does, refusing by name is the honest half.
     *
     * @return array<string,string>|null
     */
    private function refuseInstanceId(string $id): ?array
    {
        if (null === OccurrenceId::parse($id)) {
            return null;
        }

        return [
            'type' => 'invalidArguments',
            'description' => 'This id names one occurrence of a series, which CalendarEvent/set cannot write. Send the series id — "seriesId" on the instance object — and patch "recurrenceOverrides" under this instance\'s "recurrenceId".',
        ];
    }

    /**
     * @param list<Calendar> $touched
     */
    private function remember(array &$touched, CalendarEvent $event): void
    {
        $calendar = $event->calendar;

        if (null === $calendar) {
            return;
        }

        $touched[] = $calendar;
    }

    /**
     * Ask for a sync now rather than waiting for the sweep.
     *
     * Silent on a calendar that mirrors nothing, which is most of them, and at
     * most one message per calendar however many events a single /set touched.
     *
     * @param list<Calendar> $calendars
     */
    private function dispatchSync(array $calendars): void
    {
        $dispatched = [];

        foreach ($calendars as $calendar) {
            if (false === $calendar->isSynced()) {
                continue;
            }

            $calendarId = (int) $calendar->id;

            if (true === in_array($calendarId, $dispatched, true)) {
                continue;
            }

            $dispatched[] = $calendarId;

            $this->bus->dispatch(new SyncCalendarMessage($calendarId));
        }
    }
}
