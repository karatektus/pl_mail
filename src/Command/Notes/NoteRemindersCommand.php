<?php

declare(strict_types=1);

namespace App\Command\Notes;

use App\Repository\Note\NoteRepository;
use App\Service\Note\NoteReminderSender;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends note reminders that have come due: once a minute, like
 * app:calendar:alerts, and for the same reason — a minute is the unit a
 * reminder is set in. Each reminder is claimed before it is sent, so two runs
 * never send one twice, and one that fails is not retried (a reminder an hour
 * late is not a reminder). Missed by more than an hour, it is dropped.
 */
#[AsCommand(name: 'app:notes:reminders', description: 'Send note reminders that have come due')]
final class NoteRemindersCommand extends Command
{
    private const string LOOKBACK = '-1 hour';

    public function __construct(
        private readonly NoteRepository     $notes,
        private readonly NoteReminderSender $sender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now  = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sent = 0;

        foreach ($this->notes->findDueReminders($now, $now->modify(self::LOOKBACK)) as $note) {
            if (false === $this->notes->claimReminder($note, $now)) {
                continue;
            }

            if (true === $this->sender->send($note)) {
                ++$sent;
            }
        }

        if ($sent > 0) {
            new SymfonyStyle($input, $output)->success(sprintf('Sent %d note reminder(s).', $sent));
        }

        return Command::SUCCESS;
    }
}
