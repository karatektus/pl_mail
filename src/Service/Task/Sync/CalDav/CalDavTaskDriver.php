<?php

declare(strict_types=1);

namespace App\Service\Task\Sync\CalDav;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\RemoteTask;
use App\Domain\DTO\Task\RemoteTaskList;
use App\Domain\DTO\Task\RemoteTaskWriteResult;
use App\Domain\DTO\Task\TaskChangeSet;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\TaskSyncDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Service\Calendar\Sync\CalDav\CalDavClient;
use App\Service\Calendar\Sync\CalDav\CalDavCollectionReader;
use App\Service\Calendar\Sync\CalDav\CalDavDiscovery;
use App\Service\Calendar\Sync\CalDav\DavResource;
use App\Service\Setup\PublicUrlSetting;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Task lists on any RFC 4791 server: a collection that says it holds VTODOs.
 *
 * Reads through CalDavCollectionReader, the calendar driver's own, and addresses
 * resources the way that driver does: every remote id is an absolute URL, and a
 * capability is asked for rather than assumed. A calendar and a task list on one
 * collection are two rows with two tokens; see the reader on why they do not
 * notice each other.
 */
final readonly class CalDavTaskDriver implements TaskSyncDriverInterface
{
    public function __construct(
        private CalDavClient           $client,
        private CalDavDiscovery        $discovery,
        private CalDavCollectionReader $reader,
        private CalDavTaskConverter    $converter,
        private UserTimezoneResolver   $timezones,
        private PublicUrlSetting       $publicUrl,
        private LoggerInterface        $logger,
    ) {}

    public function supports(CalendarSource $source): bool
    {
        return in_array($source->integrationProvider(), [Provider::CalDav, Provider::Nextcloud], true);
    }

    public function discover(CalendarSource $source): array
    {
        $integration = $source->integration;

        if (null === $integration) {
            throw new CalendarSyncPermanentException('This source is not a CalDAV connection, so there is nothing to discover.');
        }

        $endpoint = $this->discovery->endpointFor($integration);
        $url      = $endpoint->collection ?? (string) $endpoint->home;
        $lists    = [];

        foreach ($this->reader->listCollections($integration, $endpoint) as $resource) {
            if (false === $resource->isCalendarCollection() || false === $this->holdsTasks($resource)) {
                continue;
            }

            $href = $this->client->absolutise($resource->href, $url);

            $lists[] = new RemoteTaskList(
                remoteId:   $href,
                name:       $this->nameOf($resource, $href),
                color:      $this->colourOf($resource),
                isReadOnly: $resource->isReadOnly(),
            );
        }

        return $lists;
    }

    public function pull(TaskList $list, ?string $syncToken): TaskChangeSet
    {
        $read = $this->reader->read($this->integrationOf($list), $this->collectionOf($list), $syncToken, 'VTODO');

        if (true === $read->requiresFullResync) {
            return TaskChangeSet::resyncRequired();
        }

        $zone  = $this->timezones->resolve($list->usr);
        $tasks = [];

        foreach ($read->changes as $change) {
            if (true === $change->isDeleted()) {
                $tasks[] = RemoteTask::deleted($change->href);

                continue;
            }

            $task = $this->converter->toRemoteTask((string) $change->data, $change->href, $change->etag, $zone);

            if (null === $task) {
                // An event, on a collection holding both. The calendar's business.
                $this->logger->debug('CalDavTasks: skipped a resource holding no VTODO', ['href' => $change->href]);

                continue;
            }

            $tasks[] = $task;
        }

        return new TaskChangeSet($tasks, $read->nextSyncToken);
    }

    public function push(TaskList $list, Task $task): RemoteTaskWriteResult
    {
        $integration = $this->integrationOf($list);
        $isCreate    = null === $task->remoteId;
        $href        = $task->remoteId ?? sprintf('%s/%s.ics', rtrim($this->collectionOf($list), '/'), rawurlencode($task->uid));
        $headers     = ['Content-Type' => 'text/calendar; charset=utf-8'];

        if (true === $isCreate) {
            // CalDavCalendarDriver::push()'s guard: a UID-derived href is
            // predictable, and this makes the second client a 412, not an overwrite.
            $headers['If-None-Match'] = '*';
        } elseif (null !== $task->remoteEtag) {
            $headers['If-Match'] = $task->remoteEtag;
        }

        // An install with no address on record can give a kept file no link. It stays
        // here all the same, and the link appears once one is set.
        $ics = $this->converter->toIcs(
            $task,
            new DateTimeImmutable(),
            $this->timezones->resolve($list->usr),
            (string) $this->publicUrl->current(),
        );

        $response = $this->client->request($integration, 'PUT', $href, [
            'headers' => $headers,
            'body'    => $ics,
        ], [412]);

        if (412 === $response['status']) {
            throw new CalendarResyncRequiredException(
                'The task changed on the server before our copy could be saved, so the list is being read again.',
                412,
            );
        }

        return new RemoteTaskWriteResult($href, $this->client->etagAfterWrite($integration, $href, $response['headers']), $ics);
    }

    public function delete(TaskList $list, Task $task): void
    {
        if (null === $task->remoteId) {
            // A guard rather than a case that happens: sending this to the
            // collection's own URL would delete the whole list.
            return;
        }

        $headers  = null === $task->remoteEtag ? [] : ['If-Match' => $task->remoteEtag];
        $response = $this->client->request($this->integrationOf($list), 'DELETE', $task->remoteId, [
            'headers' => $headers,
        ], [404, 410, 412]);

        if (412 === $response['status']) {
            throw new CalendarResyncRequiredException(
                'The task changed on the server before it could be deleted, so the list is being read again.',
                412,
            );
        }
    }

    /**
     * RFC 4791 reads an absent set as "anything", but offering every event
     * calendar on a server as a task list would bury the real ones. Absence is a
     * yes all the same: Radicale publishes none, and it would otherwise offer nothing.
     */
    private function holdsTasks(DavResource $resource): bool
    {
        if (false === $resource->hasProp('supported-calendar-component-set')) {
            return true;
        }

        return $resource->names('supported-calendar-component-set', 'VTODO');
    }

    private function nameOf(DavResource $resource, string $href): string
    {
        $name = $resource->prop('displayname');

        if (null !== $name) {
            return $name;
        }

        $segment = basename(rtrim((string) parse_url($href, PHP_URL_PATH), '/'));

        return '' === $segment ? 'Tasks' : rawurldecode($segment);
    }

    /** CalDavCalendarDriver::colourOf()'s reading: Apple's #rrggbbaa, alpha dropped. */
    private function colourOf(DavResource $resource): ?string
    {
        $hex = ltrim(trim((string) $resource->prop('calendar-color')), '#');

        return 1 === preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $hex)
            ? '#' . mb_strtolower(substr($hex, 0, 6))
            : null;
    }

    private function integrationOf(TaskList $list): Integration
    {
        return $list->integration
            ?? throw new CalendarSyncPermanentException('This list is not connected to a CalDAV server any more.');
    }

    private function collectionOf(TaskList $list): string
    {
        return $list->remoteId
            ?? throw new CalendarSyncPermanentException('This list has no address on the server. Subscribe to it again.');
    }
}
