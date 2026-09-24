<?php

declare(strict_types=1);

namespace App\Service\System\Update;

use App\Domain\DTO\System\PublishedBuild;
use App\Domain\DTO\System\UpdateStatus;
use App\Domain\Enum\System\UpdateChannel;
use App\Domain\Enum\System\UpdateVerdict;
use App\Domain\Exception\UpdateCheckFailedException;
use App\Entity\System\UpdateCheck;
use App\Repository\System\UpdateCheckRepository;
use App\Service\System\AppVersion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Is there a newer build on this installation's channel?
 *
 * Asked hourly by app:updates:check and on demand from Admin → Updates. The
 * registry says what the channel's newest image is (ImageRegistry); the
 * repository says how it relates to the running build (SourceHistory). Each new
 * build is announced to the administrators once (UpdateNotifier).
 *
 * A check that gets no answer keeps the last one and records why, so the page
 * can say "no newer build as of this morning; since then the registry has been
 * unreachable" rather than forgetting what it knew.
 */
final readonly class UpdateChecker
{
    public function __construct(
        private UpdateCheckRepository  $checks,
        private ImageRegistry          $registry,
        private SourceHistory          $history,
        private AppVersion             $version,
        private UpdateNotifier         $notifier,
        private EntityManagerInterface $entityManager,
        private LoggerInterface        $logger,
    ) {
    }

    /** The channel in force: the administrator's choice, or the one this build came from. */
    public function channel(?UpdateCheck $check = null): UpdateChannel
    {
        return ($check ?? $this->checks->current())->channel ?? UpdateChannel::forBuild($this->version->label());
    }

    /**
     * The last answer, if it is about the build that is running now. After an
     * upgrade the stored one describes the build that was replaced.
     */
    public function status(?UpdateCheck $check = null): ?UpdateStatus
    {
        $check ??= $this->checks->current();
        $status = UpdateStatus::fromArray($check?->status);

        if (null === $status || false === $status->isAbout($this->version->fullCommit())) {
            return null;
        }

        return $status->channel === $this->channel($check) ? $status : null;
    }

    /** The newer build this installation could install, or null. What the admin header asks. */
    public function available(): ?PublishedBuild
    {
        $status = $this->status();

        return UpdateVerdict::Available === $status?->verdict ? $status->latest : null;
    }

    public function check(): UpdateCheck
    {
        $check   = $this->checks->currentOrNew();
        $channel = $this->channel($check);

        $check->checkedAt = new DateTimeImmutable();

        if (UpdateChannel::Off === $channel) {
            $check->error  = null;
            $check->status = null;

            return $this->save($check);
        }

        try {
            $status = $this->ask($channel);
        } catch (UpdateCheckFailedException $e) {
            $check->error = $e->getMessage();

            $this->logger->warning('UpdateChecker: no answer from the update channel', [
                'channel' => $channel->value,
                'error'   => $e->getMessage(),
            ]);

            return $this->save($check);
        }

        $check->error  = null;
        $check->status = $status->toArray();

        // Once per build. The hourly check finds the same newer build every
        // hour until somebody installs it, and a notification each time would
        // teach people to ignore it.
        if (UpdateVerdict::Available === $status->verdict && $check->notifiedRevision !== $status->latest->revision) {
            $this->notifier->notify($status);
            $check->notifiedRevision = $status->latest->revision;
        }

        return $this->save($check);
    }

    /**
     * @throws UpdateCheckFailedException
     */
    private function ask(UpdateChannel $channel): UpdateStatus
    {
        $latest  = $this->registry->newest((string) $channel->imageTag());
        $running = $this->version->fullCommit();

        if (null === $running) {
            return new UpdateStatus(UpdateVerdict::Unknown, $channel, null, $latest);
        }

        if ($latest->revision === $running) {
            return new UpdateStatus(UpdateVerdict::Current, $channel, $running, $latest, 0);
        }

        $comparison = null === $latest->source ? null : $this->history->compare($latest->source, $running, $latest->revision);

        if (null !== $comparison) {
            return new UpdateStatus(
                true === $comparison->hasNewer() ? UpdateVerdict::Available : UpdateVerdict::Current,
                $channel,
                $running,
                $latest,
                $comparison->aheadBy,
                $comparison->changes,
                $comparison->url,
            );
        }

        return new UpdateStatus($this->withoutHistory($channel, $latest), $channel, $running, $latest);
    }

    /**
     * What the versions alone can say, when the repository cannot be asked.
     * Two releases compare by number. Main only moves forward, so a different
     * main build is a newer one. Anything else cannot be ordered and is not
     * announced as an update.
     */
    private function withoutHistory(UpdateChannel $channel, PublishedBuild $latest): UpdateVerdict
    {
        $running = ltrim($this->version->label(), 'v');

        if (true === $latest->isRelease() && 1 === preg_match('/^\d+\.\d+\.\d+$/', $running)) {
            return version_compare(ltrim($latest->version, 'v'), $running, '>') ? UpdateVerdict::Available : UpdateVerdict::Current;
        }

        return UpdateChannel::Main === $channel ? UpdateVerdict::Available : UpdateVerdict::Unknown;
    }

    private function save(UpdateCheck $check): UpdateCheck
    {
        $this->entityManager->persist($check);
        $this->entityManager->flush();

        return $check;
    }
}
