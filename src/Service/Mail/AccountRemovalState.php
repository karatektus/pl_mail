<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\Account;
use App\Repository\Job\BackgroundJob\BackgroundJobRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Whether an account is on its way out (#672) — the question every page asks
 * while AccountRemoval's job runs.
 *
 * Apart from AccountRemoval because of who asks. The settings list asks it
 * through a Twig function, so it is built with Twig, on every page; starting a
 * removal needs AccountServiceSwitch, and with it the push registry and every
 * provider client behind that. A Twig extension holding that chain built all of
 * it on the first render, before a test could swap one of those clients out —
 * and a page that only asks a question has no business building a JMAP client.
 *
 * Read off the job rather than written on the account, so there is nothing to
 * clear when a worker dies: the reaper fails a job that stopped moving, and the
 * account comes back as an ordinary row on its own.
 */
final readonly class AccountRemovalState
{
    public function __construct(
        private BackgroundJobRepository $jobs,
    ) {
    }

    /**
     * The removals under way for this person, by account id.
     *
     * Off the same query as the indicator, so a job the indicator has stopped
     * showing as active — finished, failed, or stranded long enough for the
     * reaper — stops greying out its row at the same moment.
     *
     * @return array<int, BackgroundJob>
     */
    public function activeFor(UserInterface $user): array
    {
        $removals = [];

        foreach ($this->jobs->findVisibleForUser($user) as $job) {
            if (JobKind::DeleteAccount === $job->kind && true === $job->isActive()) {
                $removals[(int) ($job->view['accountId'] ?? 0)] = $job;
            }
        }

        return $removals;
    }

    public function isRemoving(Account $account): bool
    {
        return null !== $account->usr && isset($this->activeFor($account->usr)[(int) $account->id]);
    }
}
