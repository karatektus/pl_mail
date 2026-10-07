<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\HeldMailReleaser;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows any mail that has been held for the assistant longer than it may be.
 *
 * THE LAST OF THREE WAYS OUT OF A HOLD, and the only one that needs no queue
 * at all. The assistant's answer releases a message; failing that, a timed
 * message queued when it was held does. Both are Messenger jobs. If the
 * transport is backed up or a worker is down, neither may happen in time — and
 * a held message is one nobody can see in their inbox.
 *
 * So this runs every minute on the scheduler, on the maintenance worker, and
 * finds nothing on every run of every ordinary day. It is here for the day it
 * finds something: mail can be seconds late, and with this it can be a minute
 * late, but it cannot be lost from view.
 *
 * A GRACE ON TOP OF THE CEILING, so this does not race the timed release it is
 * the backstop for. The two would both be correct — a release is idempotent —
 * but each would announce to open pages, and there is no reason to.
 */
#[AsCommand(
    name: 'app:mail:release-held',
    description: 'Show mail that has waited for the assistant longer than the configured maximum.',
)]
final class ReleaseHeldMailCommand extends Command
{
    /** Seconds past the ceiling before this steps in. */
    private const int GRACE_SECONDS = 15;

    public function __construct(
        private readonly MessageRepository    $messages,
        private readonly AiSettingsRepository $settings,
        private readonly HeldMailReleaser     $releaser,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ceiling = $this->settings->currentOrDefault()->holdSeconds() + self::GRACE_SECONDS;
        $overdue = $this->messages->findHeldBefore(new DateTimeImmutable(sprintf('-%d seconds', $ceiling)));

        if ([] === $overdue) {
            return Command::SUCCESS;
        }

        $released = $this->releaser->release($overdue);

        // A warning, because it means the live worker did not do its job.
        $io->warning(sprintf(
            'Released %d message(s) that were left held. Check that worker-release is running.',
            $released,
        ));

        return Command::SUCCESS;
    }
}
