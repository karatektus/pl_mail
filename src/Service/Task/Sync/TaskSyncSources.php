<?php

declare(strict_types=1);

namespace App\Service\Task\Sync;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Entity\User\User;
use App\Repository\Integration\Integration\IntegrationRepository;
use App\Repository\Mail\Account\AccountRepository;

/**
 * The accounts and connections of one user that a task driver speaks for.
 *
 * One answer for two questions: what the subscribe dialog lists, and whether the
 * pane offers that dialog at all. A button that could only ever lead to "there is
 * nothing to sync from" is not drawn, so the two have to agree. No I/O: it asks the
 * drivers' supports(), and finding a server's lists is left to the dialog.
 */
final readonly class TaskSyncSources
{
    public function __construct(
        private IntegrationRepository  $integrations,
        private AccountRepository      $accounts,
        private TaskSyncDriverRegistry $drivers,
    ) {}

    /** @return list<array{kind: string, id: int|null, label: string}> */
    public function forUser(User $user): array
    {
        $sources = [];

        foreach ($this->integrations->findForUserOrdered($user) as $integration) {
            $source = CalendarSource::ofIntegration($integration);

            if (true === $this->drivers->has($source)) {
                $sources[] = ['kind' => 'integration', 'id' => $integration->id, 'label' => self::labelOf($source)];
            }
        }

        foreach ($this->accounts->findBy(['usr' => $user]) as $account) {
            $source = CalendarSource::ofAccount($account);

            if (true === $this->drivers->has($source)) {
                $sources[] = ['kind' => 'account', 'id' => $account->id, 'label' => self::labelOf($source)];
            }
        }

        return $sources;
    }

    public static function labelOf(CalendarSource $source): string
    {
        return (string) ($source->integration->name ?? $source->account?->email);
    }
}
