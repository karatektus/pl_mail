<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Repository\Monitoring\LogEntryRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the user menu should be outlined, and why.
 *
 * The topbar renders on every authenticated page from a layout, so there is no
 * controller in the middle to thread a variable through — the same reason
 * CalendarGlobal exists.
 *
 * Admins only. The outline is a shortcut to the log browser, and for a user
 * who cannot open it, it would be an alarm about something they are not
 * allowed to look at.
 *
 * Per-request memoised, including the null: the button renders once, but a
 * global that queried on every call would be a query per include the moment
 * something else wanted this.
 */
class LogAlertGlobal implements ResetInterface
{
    /** Monolog's numeric levels, the two the outline distinguishes. */
    private const int WARNING = 300;
    private const int ERROR   = 400;

    private bool $resolved = false;

    /** @var array{tone: string, count: int}|null */
    private ?array $unseen = null;

    public function __construct(
        private readonly LogEntryRepository $logEntries,
        private readonly Security           $security,
    ) {
    }

    /**
     * Worker-mode hygiene: FrankenPHP keeps this service alive across
     * requests, so the per-request memo above is per-request only because the
     * kernel resets us between them. Without this, the first value a worker
     * computes is the value every later request - and every later USER - gets,
     * which is exactly the stale log badge that survived an emptied table.
     * InviteReader and ProposalReader already live by this rule.
     */
    public function reset(): void
    {
        $this->resolved = false;
        $this->unseen   = null;
    }

    /**
     * The worst thing logged since this admin last opened the log browser, as
     * a tone and a count, or null when there is nothing they have not seen.
     *
     * @return array{tone: string, count: int}|null
     */
    public function getUnseen(): ?array
    {
        if (true === $this->resolved) {
            return $this->unseen;
        }

        $this->resolved = true;
        $user           = $this->security->getUser();

        if (false === $user instanceof User || false === $this->security->isGranted('ROLE_ADMIN')) {
            return null;
        }

        $unseen = $this->logEntries->unseenSince($user->logsSeenAt, self::WARNING);

        if (null === $unseen['level']) {
            return null;
        }

        $this->unseen = [
            'tone'  => $unseen['level'] >= self::ERROR ? 'danger' : 'warn',
            'count' => $unseen['count'],
        ];

        return $this->unseen;
    }
}
