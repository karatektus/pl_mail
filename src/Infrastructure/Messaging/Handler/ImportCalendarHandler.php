<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\DTO\Calendar\IcsImportResult;
use App\Domain\Enum\Job\JobState;
use App\Infrastructure\Messaging\Message\ImportCalendarMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarNotifier;
use App\Service\Calendar\Ics\IcsImporter;
use App\Service\Job\JobNotifier;
use App\Service\Job\JobProgress;
use App\Service\Job\JobUploadStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Import an uploaded .ics onto the calendar the person chose (#672).
 *
 * IcsController has already checked that the file is a calendar and the
 * target accepts writes, so a wrong file is still refused in the dialog. What
 * comes here is the part that grows with the file: every meeting looked up by
 * UID and written, thousands of them for an export of a few years.
 *
 * The counts used to be the request's toast. A finished job leaves the
 * indicator at once, so they are sent as a toast of their own
 * (JobNotifier::outcome()), worded as they always were.
 *
 * Failing is recorded on the job and not rethrown: the meetings already
 * written stay, and importing the same file again updates them rather than
 * adding them twice — the UID is what an import matches on.
 */
#[AsMessageHandler]
final readonly class ImportCalendarHandler
{
    public function __construct(
        private JobProgress            $progress,
        private JobUploadStore         $uploads,
        private CalendarRepository     $calendars,
        private IcsImporter            $importer,
        private CalendarNotifier       $calendarNotifier,
        private JobNotifier            $notifier,
        private MessageBusInterface    $bus,
        private TranslatorInterface    $translator,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ImportCalendarMessage $message): void
    {
        $job = $this->progress->active($message->jobId);

        if (null === $job) {
            return;
        }

        $user     = $job->usr;
        $userId   = (int) $user->id;
        $locale   = $user->locale;
        $file     = (string) ($job->view['file'] ?? '');
        $path     = $this->uploads->path($userId, $file);
        $calendar = $this->calendars->findOneForUser($user, (int) ($job->view['calendarId'] ?? 0));

        // Deleted while the job waited, or the file swept as stale.
        if (null === $calendar || null === $path) {
            $this->progress->finish($message->jobId, JobState::Failed, $this->translator->trans('calendar.ics.error.import_gone', [], null, $locale));
            $this->uploads->forget($userId, $file);

            return;
        }

        try {
            $result = $this->importer->import(
                $calendar,
                $user,
                (string) file_get_contents($path),
                function (int $done, int $total) use ($message): void {
                    // Flushed with the job: the meetings written so far are
                    // committed as the bar moves, rather than all at the end.
                    0 === $done
                        ? $this->progress->begin($message->jobId, $total)
                        : $this->progress->advance($message->jobId, $done);
                },
            );

            $this->em->flush();

            // After the flush, so the worker reads committed rows. Silent
            // unless the calendar mirrors something writable, which is the only
            // case where an import owes anybody a push.
            if (true === $result->changedAnything() && true === $calendar->isSynced()) {
                $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));
            }

            $this->progress->finish($message->jobId, JobState::Done);

            // An open calendar redraws with the new meetings on it.
            $this->calendarNotifier->publishCalendarChanged($user);

            [$key, $parameters] = self::toast($result);
            $this->notifier->outcome($user, $this->translator->trans($key, $parameters, null, $locale));
        } catch (Throwable $e) {
            $this->logger->error('ImportCalendarHandler: the import failed', [
                'jobId'     => $message->jobId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->progress->finish($message->jobId, JobState::Failed, $e->getMessage());
        } finally {
            $this->uploads->forget($userId, $file);
        }
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
    private static function toast(IcsImportResult $result): array
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
}
