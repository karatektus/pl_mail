<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Interface\UpgradeTaskInterface;
use App\Infrastructure\Event\Listener\ArrivalStamper;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives the last week of mail the provider accept time that mail stored from
 * now on is given as it arrives.
 *
 * Admin → Performance → "How late mail arrives" measures from that time and
 * leaves out mail that has none. Without this the panel would be empty on the
 * day of the update and for a week be a picture of less than it claims to
 * cover — on the page somebody opens precisely because a number looked wrong.
 *
 * A WEEK AND NO FURTHER, because that is the longest period the page offers.
 * Everything older would be parsed, written and never read.
 *
 * WHAT IT CANNOT PUT BACK. The time is in the stored headers and is recovered
 * exactly. What started the sync is not recorded anywhere and stays empty; the
 * page shows a dash. Where the mail was filed is taken from where it is now —
 * see ArrivalStamper::stamp().
 *
 * Idempotent: a message that has been given a time is no longer selected, and
 * one that cannot be given one — a draft, a sent copy — is passed over again
 * at the cost of reading it.
 */
final readonly class MessageArrivalBackfillTask implements UpgradeTaskInterface
{
    private const int BATCH_SIZE = 200;

    private const string REACH = '-8 days';

    public function __construct(
        private MessageRepository      $messages,
        private ArrivalStamper         $stamper,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'message-arrival';
    }

    public function getDescription(): string
    {
        return 'Work out when the provider accepted each of the last week\'s messages, for Admin → Performance.';
    }

    public function run(SymfonyStyle $io): int
    {
        $firstId = $this->messages->firstIdStoredSince(new DateTimeImmutable(self::REACH));

        if (null === $firstId) {
            $io->success('Nothing to do — no mail was stored in the last week.');

            return Command::SUCCESS;
        }

        $lastId  = $firstId - 1;
        $stamped = 0;

        while (true) {
            $batch = $this->messages->findWithoutAcceptTime($lastId, self::BATCH_SIZE);

            if (count($batch) === 0) {
                break;
            }

            foreach ($batch as $message) {
                $lastId = (int) $message->id;

                if (true === $this->stamper->stamp($message)) {
                    ++$stamped;
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->success(sprintf('%d message(s) given an accept time.', $stamped));

        return Command::SUCCESS;
    }
}
