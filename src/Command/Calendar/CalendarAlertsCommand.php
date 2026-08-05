<?php

declare(strict_types=1);

namespace App\Command\Calendar;

use App\Repository\Calendar\CalendarAlertDeliveryRepository;
use App\Service\Calendar\Alert\AlertDeliverer;
use App\Service\Calendar\Alert\DueAlertReader;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The sweep that makes an alert an alert rather than a stored preference.
 *
 * Runs every minute, which is the same cadence and the same reasoning as
 * app:mail:wake-snoozed: a minute is the unit people set a reminder in, and the
 * interval is the bound on how late an alert can be. Five minutes would mean a
 * "five minutes before" alert arriving anywhere between on time and after the
 * meeting started, which is not a reminder.
 *
 * It costs one indexed query when nothing is due, which is almost always — the
 * candidate query is bounded on `starts_at` and asks for events carrying an
 * `alerts` key, and on a calendar with no alerts at all it matches nothing.
 *
 * **Inline rather than dispatched**, unlike app:calendar:sync. That is the one
 * decision here worth arguing: sync dispatches because it talks to a third party
 * with a retry policy that belongs on the transport, and this talks to a push
 * service and an SMTP host, which is the same shape. The difference is what a
 * retry would mean. A sync retried in ten minutes is a sync; an alert retried in
 * ten minutes is a lie about when something starts, and AlertDeliverer therefore
 * claims each alert before sending it and never re-queues one. Putting that
 * between a bus and a worker would add a window in which the claim exists and
 * the message has not been consumed — which is the same lost alert, with more
 * machinery.
 *
 * Safe to run by hand, and safe to run twice: the claim is what makes it
 * idempotent, not the schedule.
 */
#[AsCommand(
    name: 'app:calendar:alerts',
    description: 'Deliver calendar alerts that have come due, and prune the records of ones long past',
)]
final class CalendarAlertsCommand extends Command
{
    /**
     * How long a delivery record is kept.
     *
     * It only has to outlive the window in which its alert could still be
     * claimed — DueAlertReader::LOOKBACK, one hour — so a week is not caution,
     * it is somewhere to look when a user asks why they were not reminded. A
     * cutoff shorter than the lookback would let an alert fire a second time,
     * which is the one thing this table exists to prevent.
     */
    private const string KEEP_DELIVERIES_FOR = '-7 days';

    public function __construct(
        private readonly DueAlertReader                  $due,
        private readonly AlertDeliverer                  $deliverer,
        private readonly CalendarAlertDeliveryRepository $deliveries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List what is due without delivering it or writing anything down',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();
        $due = $this->due->due($now);

        if (true === $input->getOption('dry-run')) {
            foreach ($due as $alert) {
                $io->text(sprintf(
                    '→ %s (event #%d, %s) due %s',
                    $alert->alert->action->value,
                    $alert->eventId,
                    $alert->alert->key,
                    $alert->triggerAt->format('Y-m-d H:i:s'),
                ));
            }

            $io->success(sprintf('%d alert(s) due.', count($due)));

            return Command::SUCCESS;
        }

        $delivered = 0;

        foreach ($due as $alert) {
            if (true === $this->deliverer->deliver($alert, $now)) {
                ++$delivered;
            }
        }

        // After the sweep rather than before it, so a prune that fails on a lock
        // cannot cost this run its deliveries. Nothing reads a record older than
        // the lookback window, so it can wait a minute.
        $pruned = $this->deliveries->pruneBefore($now->modify(self::KEEP_DELIVERIES_FOR));

        if (0 === $delivered && 0 === $pruned) {
            return Command::SUCCESS;
        }

        $io->success(sprintf('Delivered %d alert(s); pruned %d record(s).', $delivered, $pruned));

        return Command::SUCCESS;
    }
}
