<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One read of a user's mail accounts, shared by everything that renders in the
 * same request.
 *
 * ── The duplication this removes ────────────────────────────────────────────
 * Every mail list page read the account table twice: the sidebar's account
 * section through AccountsGlobal, which wants the switched-on ones, and the
 * topbar's health dot through AccountHealthInspector, which wants all of them
 * because a switched-off account with a dead grant is still a dead grant. Two
 * services, two correct questions, one table, and the narrower answer sitting
 * inside the wider one.
 *
 * ── Why the superset is the one that gets read ──────────────────────────────
 * active() is all() filtered on isActive rather than a query of its own.
 * AccountRepository::findActiveForUserOrdered() is findForUserOrdered() plus
 * `account.isActive = true`, with the same `sortOrder, LOWER(COALESCE(email,
 * username))` ordering in both — so the filtered superset is the subset, row
 * for row and in the same order. That ordering is load-bearing: see the
 * docblock on findActiveForUserOrdered(), where an ordering of the sidebar's
 * own made the settings page's drag handles a control over nothing.
 *
 * `true === $account->isActive` and not a truthiness test, because the column
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

    public function __construct(
        private readonly AccountRepository $accounts,
    ) {
    }

    public function reset(): void
    {
        $this->byUser = [];
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
     * The ones that are switched on — the same set, same order, minus the
     * accounts the user has paused.
     *
     * @return list<Account>
     */
    public function active(UserInterface $user): array
    {
        return array_values(array_filter(
            $this->all($user),
            static fn (Account $account): bool => true === $account->isActive,
        ));
    }
}
