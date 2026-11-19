<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Infrastructure\Messaging\Message\ImportContactsMessage;
use App\Service\Contacts\CsvImporter;
use App\Service\Contacts\ImportRunner;
use App\Service\Contacts\VCardImporter;
use App\Service\Job\JobNotifier;
use App\Service\Job\JobProgress;
use App\Service\Job\JobUploadStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Import a contacts file the person confirmed after its preview (#672).
 *
 * It used to run inside the confirm request, and a thousand contacts took over
 * forty seconds there — past PHP's thirty, so a modest address book failed
 * with "something went wrong" and imported nothing. Here it runs through
 * ImportRunner in chunks, with the jobs indicator counting contacts.
 *
 * Failing is recorded on the job and not rethrown, as the other job handlers
 * do: the contacts already in stay in, and a redelivery would import them a
 * second time. The duplicates page is where a partial import is tidied up,
 * and a fresh upload is the retry.
 */
#[AsMessageHandler]
final readonly class ImportContactsHandler
{
    public function __construct(
        private JobProgress         $progress,
        private JobUploadStore      $uploads,
        private VCardImporter       $vcards,
        private CsvImporter         $csv,
        private ImportRunner        $runner,
        private JobNotifier         $notifier,
        private TranslatorInterface $translator,
        private LoggerInterface     $logger,
    ) {
    }

    public function __invoke(ImportContactsMessage $message): void
    {
        $job = $this->progress->active($message->jobId);

        if (null === $job) {
            return;
        }

        $user   = $job->usr;
        $userId = (int) $user->id;
        $file   = (string) ($job->view['file'] ?? '');
        $format = (string) ($job->view['format'] ?? '');
        $path   = $this->uploads->path($userId, $file);

        // Swept as stale, or a redelivery after the file was already used.
        if (null === $path || false === in_array($format, ['vcf', 'csv'], true)) {
            $this->progress->finish($message->jobId, JobState::Failed, $this->translator->trans('contacts.import_expired', [], null, $user->locale));

            return;
        }

        try {
            $content = (string) file_get_contents($path);
            $dtos    = 'vcf' === $format ? $this->vcards->import($content) : $this->csv->import($content);

            $this->progress->begin($message->jobId, count($dtos));

            $result = $this->runner->run(
                $user,
                $dtos,
                'import:' . $format,
                fn (int $done) => $this->progress->advance($message->jobId, $done),
            );

            $this->progress->finish($message->jobId, JobState::Done);

            // What the confirm page used to say on its way to Duplicates. A
            // finished job leaves the indicator at once, so it is a toast now.
            $this->notifier->outcome($user, $this->translator->trans(
                'contacts.imported',
                ['%count%' => count($result['imported'])],
                null,
                $user->locale,
            ));
        } catch (Throwable $e) {
            $this->logger->error('ImportContactsHandler: the import failed', [
                'jobId'     => $message->jobId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->progress->finish($message->jobId, JobState::Failed, $e->getMessage());
        } finally {
            $this->uploads->forget($userId, $file);
        }
    }
}
