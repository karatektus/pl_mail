<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\Account\AccountRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One read of a user's mail accounts, shared by everything that renders in the
 * same request.
 *
 * ── The duplication this removes ────────────────────────────────────────────
 * Every mail list page read the account table twice: the sidebar's account
 * section through AccountsGlobal, which wants the ones it shows, and the
 * topbar's health dot through AccountHealthInspector, which wants all of them
 * because a switched-off account with a dead grant is still a dead grant. Two
 * services, two correct questions, one table, and the narrower answer sitting
 * inside the wider one.
 *
 * ── Why the superset is the one that gets read ──────────────────────────────
 * active() is all() filtered on isActive rather than a query of its own.
 * AccountRepository::findActiveForUserOrdered() is findForUserOrdered() plus
 * `account.mailEnabled = true`, with the same `sortOrder, LOWER(COALESCE(email,
 * username))` ordering in both — so the filtered superset is the subset, row
 * for row and in the same order. That ordering is load-bearing: see the
 * docblock on findActiveForUserOrdered(), where an ordering of the sidebar's
 * own made the settings page's drag handles a control over nothing.
 *
 * `true === $account->mailEnabled` and not a truthiness test, because the column
 * is NULLABLE. A null isActive fails `= true` in SQL — NULL = true is NULL, not
 * false — so a strict comparison is what reproduces the query, and `false ===`
 * or `!` would not: both would let a null through.
 *
 * ── Per request, and only for readers ───────────────────────────────────────
 * Resettable for the reason AccountsGlobal already was: FrankenPHP keeps this
 * alive between requests, and a list of accounts held across them is somebody
 * else's.
 *
 * The write paths keep going through the repository directly — AccountCreator
 * and OAuthAccountLinker read the existing accounts in order to decide what to
 * create or promote — because a memo answering from before their own insert is
 * exactly the kind of staleness that is worth more than the query it saves.
 */
final class UserAccounts implements ResetInterface
{
    /**
     * user identifier => that user's accounts, in the settings page's order.
     *
     * Keyed rather than single-slot because a console command or a worker can
     * legitimately walk several users inside one "request", and the identifier
     * rather than the object so two loads of the same user share the answer.
     *
     * @var array<string, list<Account>>
     */
    private array $byUser = [];

    /**
     * user identifier => the ids of that user's mail-off accounts that still
     * hold mail, memoised beside the accounts for the same reason.
     *
     * @var array<string, list<int>>
     */
    private array $holdingMail = [];

    public function __construct(
        private readonly AccountRepository $accounts,
    ) {
    }

    public function reset(): void
    {
        $this->byUser      = [];
        $this->holdingMail = [];
    }

    /**
     * Every account the user has, switched off ones included.
     *
     * @return list<Account>
     */
    public function all(UserInterface $user): array
    {
        return $this->byUser[$user->getUserIdentifier()] ??= array_values(
            $this->accounts->findForUserOrdered($user),
        );
    }

    /**
     * The ones whose mail is switched on — the same set, same order, minus the
     * accounts the user has paused or keeps for calendar and contacts alone.
     *
     * @return list<Account>
     */
    public function active(UserInterface $user): array
    {
        return array_values(array_filter(
            $this->all($user),
            static fn (Account $account): bool => true === $account->mailEnabled,
        ));
    }

    /**
     * The accounts the mail sidebar lists: mail on, or mail off with mail still
     * here to read.
     *
     * The second half is what keeps "mail off" from meaning "mail gone". An
     * account switched off keeps its folders and its threads, and they are
     * reached through its row here — dimmed, and named as switched off. An
     * account that has never held mail is left out, because a row that opens
     * onto nothing is not somewhere anybody would want to go.
     *
     * @return list<Account>
     */
    public function inMail(UserInterface $user): array
    {
        $all = $this->all($user);

        $mailOff = array_filter($all, static fn (Account $account): bool => false === $account->mailEnabled);

        if ([] === $mailOff) {
            return $all;
        }

        $holding = $this->holdingMail[$user->getUserIdentifier()] ??= $this->accounts->findMailOffIdsHoldingMail($user);

        return array_values(array_filter(
            $all,
            static fn (Account $account): bool => true === $account->mailEnabled
                || true === in_array((int) $account->id, $holding, true),
        ));
    }
}
