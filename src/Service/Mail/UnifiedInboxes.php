<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\UnifiedInboxIcon;
use App\Domain\Exception\InvalidUnifiedInboxException;
use App\Entity\Mail\Account;
use App\Entity\Mail\UnifiedInbox;
use App\Entity\Mail\UnifiedInboxAccount;
use App\Entity\User\User;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\UnifiedInboxAccount\UnifiedInboxAccountRepository;
use App\Repository\Mail\UnifiedInbox\UnifiedInboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The reader's unified inboxes and which accounts each one lists (#265).
 *
 * Everything that asks "which accounts does this inbox show" asks here — the
 * list, its counts, the tab strip, the sidebar badges, select-all and the JMAP
 * object — so that none of them can come to a different answer about the same
 * account.
 *
 * ## Where an account is
 *
 *   - assigned to an inbox (UnifiedInboxAccount): in that one;
 *   - not assigned at all: in the reader's FIRST inbox, the one at the top of
 *     the sidebar that is not Other. That is where a new account turns up,
 *     and every account of somebody who never opened the settings.
 *
 * Other is never "not assigned". Taking an account out of an inbox assigns it
 * to Other, and so does deleting the inbox it was in. A reader who wants a new
 * account kept out of their main inbox moves it once, and it stays moved.
 *
 * ## The starting rows
 *
 * Every reader has at least one inbox of their own and exactly one Other,
 * made here the first time anything asks — for an existing reader on the first
 * page after the update, which is why the migration does not make them. Made
 * on read rather than on sign-up because users are created in a dozen places —
 * registration, the admin, the installer, fixtures — and every one of them
 * would have to remember. With no assignments yet, the first inbox holds every
 * account: the sidebar looks exactly as it did before.
 *
 * Memoised per request and per reader; reset between requests in worker mode.
 */
final class UnifiedInboxes implements ResetInterface
{
    /** @var array<int, list<UnifiedInbox>> user id => their inboxes in order */
    private array $inboxes = [];

    /** @var array<int, list<Account>> user id => their accounts, settings order */
    private array $accounts = [];

    /** @var array<int, array<int, list<int>>> user id => (account id => group ids) */
    private array $membership = [];

    /** @var array<int, array<int, bool>> user id => (account id => switched on) */
    private array $active = [];

    public function __construct(
        private readonly UnifiedInboxRepository        $repository,
        private readonly UnifiedInboxAccountRepository $assignments,
        private readonly AccountRepository             $accountRepository,
        private readonly EntityManagerInterface        $em,
        private readonly UnifiedInboxChanges           $changes,
    ) {
    }

    public function reset(): void
    {
        $this->inboxes    = [];
        $this->accounts   = [];
        $this->membership = [];
        $this->active     = [];
    }

    /**
     * How an inbox list names itself to select-all and the bulk actions: the
     * inbox id, a colon, and the tab key — `12:primary`, `12:c4`, or `12:`
     * for somebody with the tabs switched off. See ListViewResolver.
     *
     * The id goes in the value rather than a field of its own because the
     * value is what a background job stores and resolves when it RUNS, and a
     * whole-view archive has to reach the inbox that was on screen when the
     * button was pressed.
     */
    public static function listValue(UnifiedInbox $inbox, string $tab): string
    {
        return $inbox->id . ':' . $tab;
    }

    /**
     * The inbox id and the tab key out of a list value. A value with no id —
     * one written before there were several inboxes, by a page or a job still
     * queued across the update — is the first inbox: that was the only one.
     *
     * @return array{0: int|null, 1: string}
     */
    public static function parseListValue(string $value): array
    {
        if (1 === preg_match('/^(\d+):(.*)$/s', $value, $match)) {
            return [(int) $match[1], $match[2]];
        }

        return [null, $value];
    }

    /**
     * Every inbox of the reader's, Other included, in sidebar order.
     *
     * @return list<UnifiedInbox>
     */
    public function all(User $user): array
    {
        $key = (int) $user->id;

        if (false === isset($this->inboxes[$key])) {
            $this->inboxes[$key] = $this->withStartingRows($user, $this->repository->findForUserOrdered($user));
        }

        return $this->inboxes[$key];
    }

    /**
     * The inboxes the sidebar draws: all of them, except Other while it holds
     * no switched-on account. An empty catch-all is a row that only ever opens
     * on "No messages", and on most installs nobody has moved anything there.
     *
     * An inbox the reader made stays even when empty. They made it; a row that
     * vanished whenever its last account was moved would be one they could
     * not find to put an account back into.
     *
     * @return list<UnifiedInbox>
     */
    public function shown(User $user): array
    {
        return array_values(array_filter(
            $this->all($user),
            fn (UnifiedInbox $inbox): bool => false === $inbox->isOther || true === $this->holdsActive($user, $inbox),
        ));
    }

    /** The reader's first inbox that is not Other — where `/mail/inbox` opens. */
    public function first(User $user): UnifiedInbox
    {
        foreach ($this->all($user) as $inbox) {
            if (false === $inbox->isOther) {
                return $inbox;
            }
        }

        // withStartingRows() guarantees one; this is for the type checker.
        throw new \LogicException('A reader always has an inbox of their own.');
    }

    public function other(User $user): UnifiedInbox
    {
        foreach ($this->all($user) as $inbox) {
            if (true === $inbox->isOther) {
                return $inbox;
            }
        }

        throw new \LogicException('A reader always has an Other inbox.');
    }

    /**
     * One of the reader's inboxes by id, or null for an id that is not one of
     * theirs — somebody else's, deleted, or never there. The caller decides
     * whether that is a 404 or nothing to act on.
     */
    public function find(User $user, int $id): ?UnifiedInbox
    {
        foreach ($this->all($user) as $inbox) {
            if ($id === $inbox->id) {
                return $inbox;
            }
        }

        return null;
    }

    /**
     * Which groups each of the reader's accounts is in: account id => group
     * ids, every account included, switched off or not (#783: an account may
     * be in several). An account that was never put anywhere is in the first
     * group, which is what a new account still does.
     *
     * @return array<int, list<int>>
     */
    public function membership(User $user): array
    {
        $key = (int) $user->id;

        if (true === isset($this->membership[$key])) {
            return $this->membership[$key];
        }

        $first  = (int) $this->first($user)->id;
        $other  = (int) $this->other($user)->id;
        $known  = array_map(static fn (UnifiedInbox $inbox): int => (int) $inbox->id, $this->all($user));
        $map    = [];
        $active = [];

        foreach ($this->assignments->inboxesByAccount($user) as $accountId => $row) {
            // A row naming a group that is gone cannot happen through the
            // foreign key, but a memo older than a delete in the same request
            // can; what is left, or the first group, is the safe reading.
            $groups = array_values(array_intersect($row['inboxes'], $known));

            // Other is the rest: an account in one of the reader's own groups
            // is not in it, whatever an older row says.
            if (\count($groups) > 1) {
                $groups = array_values(array_diff($groups, [$other])) ?: $groups;
            }

            $map[$accountId]    = [] === $groups ? [$first] : $groups;
            $active[$accountId] = $row['active'];
        }

        $this->active[$key] = $active;

        return $this->membership[$key] = $map;
    }

    /**
     * The groups one account is in.
     *
     * @return list<UnifiedInbox>
     */
    public function groupsOf(User $user, Account $account): array
    {
        $ids = $this->membership($user)[(int) $account->id] ?? [(int) $this->first($user)->id];

        return array_values(array_filter($this->all($user), static fn (UnifiedInbox $inbox): bool => in_array((int) $inbox->id, $ids, true)));
    }

    /**
     * The ids of the accounts a group lists.
     *
     * @return list<int>
     */
    public function accountIdsOf(User $user, UnifiedInbox $inbox): array
    {
        return array_keys(array_filter(
            $this->membership($user),
            static fn (array $groups): bool => in_array((int) $inbox->id, $groups, true),
        ));
    }

    /**
     * The account ids to narrow an inbox's queries to, or null when the inbox
     * holds every account of the reader's — the common case, one inbox over
     * everything, where narrowing would only add an IN list naming all of
     * them to every query of the page.
     *
     * @return list<int>|null
     */
    public function scopeOf(User $user, UnifiedInbox $inbox): ?array
    {
        $ids = $this->accountIdsOf($user, $inbox);

        return \count($ids) === \count($this->membership($user)) ? null : $ids;
    }

    /**
     * The accounts an inbox lists, in the reader's account order.
     *
     * @return list<Account>
     */
    public function accountsIn(User $user, UnifiedInbox $inbox): array
    {
        $ids = $this->accountIdsOf($user, $inbox);

        return array_values(array_filter(
            $this->accountsOf($user),
            static fn (Account $account): bool => true === in_array((int) $account->id, $ids, true),
        ));
    }

    /**
     * Where a new account goes (#783): the groups ticked when it was added.
     * None ticked is a choice too — in none of the reader's own groups, so
     * Other. Ids that are not the reader's groups are ignored.
     *
     * @param list<int|string> $groupIds
     */
    public function placeNew(User $user, Account $account, array $groupIds): void
    {
        $wanted = array_values(array_filter(
            $this->all($user),
            static fn (UnifiedInbox $inbox): bool => in_array((int) $inbox->id, array_map(intval(...), $groupIds), true),
        ));

        if ([] === $wanted) {
            $this->assign($user, $account, $this->other($user));

            return;
        }

        $this->assign($user, $account, $wanted[0]);

        foreach (array_slice($wanted, 1) as $inbox) {
            $this->addAccount($user, $account, $inbox);
        }
    }

    /**
     * Put an account in exactly this group and no other — the one-group move
     * #265 had, still what JMAP and a restore mean by an assignment. Other is
     * a group like the rest here: moving an account there is how it leaves
     * all of the reader's own.
     */
    public function assign(User $user, Account $account, UnifiedInbox $inbox): void
    {
        $this->assertOwn($user, $inbox);
        $this->assertOwnAccount($user, $account);

        $changed = array_map(static fn (UnifiedInbox $each): int => (int) $each->id, $this->groupsOf($user, $account));

        foreach ($this->assignments->findBy(['account' => $account]) as $row) {
            $this->em->remove($row);
        }

        $this->em->flush();
        $this->em->persist(new UnifiedInboxAccount($inbox, $account));
        $this->changes->updated($user, array_values(array_unique([...$changed, (int) $inbox->id])));
        $this->em->flush();
        $this->forget($user);
    }

    /**
     * Put an account in a group as well as the ones it is in (#783).
     *
     * An account that was never put anywhere is in the first group by rule;
     * it gets that row too, so adding it to "Work" does not take it out of
     * the first group behind the reader's back. Other is the rest, not a
     * group to put anything in: an account added to a group of the reader's
     * own leaves it.
     */
    public function addAccount(User $user, Account $account, UnifiedInbox $inbox): void
    {
        $this->assertOwn($user, $inbox);
        $this->assertOwnAccount($user, $account);

        if (true === $inbox->isOther) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.other_is_the_rest');
        }

        $rows    = $this->assignments->findBy(['account' => $account]);
        $changed = [(int) $inbox->id];

        foreach ($rows as $row) {
            if ($row->inbox->id === $inbox->id) {
                return;
            }
        }

        if ([] === $rows) {
            $first = $this->first($user);

            if ($first->id !== $inbox->id) {
                $this->em->persist(new UnifiedInboxAccount($first, $account));
            }
        }

        foreach ($rows as $row) {
            if (true === $row->inbox->isOther) {
                $this->em->remove($row);
                $changed[] = (int) $row->inbox->id;
            }
        }

        $this->em->persist(new UnifiedInboxAccount($inbox, $account));
        $this->changes->updated($user, $changed);
        $this->em->flush();
        $this->forget($user);
    }

    /**
     * Take an account out of a group. Out of its last one it goes to Other,
     * which is what Other is for. Nothing is taken out of Other: an account
     * leaves it by being put in a group of the reader's own.
     */
    public function removeAccount(User $user, Account $account, UnifiedInbox $inbox): void
    {
        $this->assertOwn($user, $inbox);
        $this->assertOwnAccount($user, $account);

        if (true === $inbox->isOther) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.other_is_the_rest');
        }

        $groups = $this->groupsOf($user, $account);
        $left   = array_values(array_filter($groups, static fn (UnifiedInbox $each): bool => $each->id !== $inbox->id));

        if (\count($left) === \count($groups)) {
            return;
        }

        $rows = $this->assignments->findBy(['account' => $account]);

        // In the first group by rule, with no rows: what it is in is written
        // down before one of them is taken away.
        if ([] === $rows) {
            foreach ($left as $each) {
                $this->em->persist(new UnifiedInboxAccount($each, $account));
            }
        }

        foreach ($rows as $row) {
            if ($row->inbox->id === $inbox->id) {
                $this->em->remove($row);
            }
        }

        $changed = [(int) $inbox->id];

        if ([] === $left) {
            $other = $this->other($user);
            $this->em->persist(new UnifiedInboxAccount($other, $account));
            $changed[] = (int) $other->id;
        }

        $this->changes->updated($user, $changed);
        $this->em->flush();
        $this->forget($user);
    }

    /**
     * A new inbox, at the bottom of the list — above Other when Other is
     * there. Empty: accounts are moved into it afterwards, one by one,
     * which is the only way an account changes inbox.
     */
    public function create(User $user, string $name, string $icon): UnifiedInbox
    {
        $inboxes  = $this->all($user);
        $last     = $inboxes[array_key_last($inboxes)];
        $position = \count($inboxes);

        // Other where the reader left it, unless that is the bottom: then the
        // new inbox goes above it, beside the reader's other inboxes, rather
        // than under the catch-all.
        if (true === $last->isOther) {
            $position       = $last->position;
            $last->position = $position + 1;
        }

        $inbox = new UnifiedInbox($user, $this->validName($name, false), $this->validIcon($icon), $position);

        $this->em->persist($inbox);
        $this->em->flush();

        // After the flush, which is what gives it the id a client keys it by;
        // Other's position moved too.
        $this->changes->created($user, [(int) $inbox->id]);
        $this->changes->updated($user, [(int) $last->id]);
        $this->em->flush();
        $this->forget($user);

        return $inbox;
    }

    /**
     * Rename an inbox and change its icon in one write. An empty name puts
     * plMail's own word back ("Inbox"), in the reader's language. Other keeps
     * its name (#783): it is the rest, and says so.
     */
    public function update(User $user, UnifiedInbox $inbox, string $name, string $icon): void
    {
        $this->assertOwn($user, $inbox);

        $inbox->name = true === $inbox->isOther ? null : $this->validName($name, true);
        $inbox->icon = $this->validIcon($icon);

        $this->changes->updated($user, [(int) $inbox->id]);
        $this->em->flush();
        $this->forget($user);
    }

    /**
     * The order of the reader's inboxes, as the whole list of ids.
     *
     * Kept to the reader's own ids, so a request cannot reach anybody else's
     * row. One the list leaves out keeps its relative place after the ones it
     * names, which makes a partial or doubled request harmless.
     *
     * @param list<int> $ids
     */
    public function arrange(User $user, array $ids): void
    {
        $byId = [];

        foreach ($this->all($user) as $inbox) {
            $byId[(int) $inbox->id] = $inbox;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (true === isset($byId[$id]) && false === isset($ordered[$id])) {
                $ordered[$id] = $byId[$id];
            }
        }

        foreach ($byId as $id => $inbox) {
            $ordered[$id] ??= $inbox;
        }

        $position = 0;
        $moved    = [];

        foreach ($ordered as $id => $inbox) {
            if ($inbox->position !== $position) {
                $moved[] = $id;
            }

            $inbox->position = $position++;
        }

        // Only what moved: the page writes after every move, a move straight
        // back included, and an unchanged order is no news to a phone.
        if ([] !== $moved) {
            $this->changes->updated($user, $moved);
        }

        $this->em->flush();
        $this->forget($user);
    }

    /**
     * Delete an inbox the reader made. Its accounts go to Other, where they
     * are still one click away, rather than to the first inbox: they were
     * kept apart on purpose, and mixing them back in is the one thing the
     * reader did not ask for.
     *
     * Other cannot be deleted — it is where everything else lands — and
     * neither can the last inbox of the reader's own: an account that is not
     * assigned anywhere is read as being in the first one, so there has to be
     * one.
     */
    public function remove(User $user, UnifiedInbox $inbox): void
    {
        $this->assertOwn($user, $inbox);

        if (true === $inbox->isOther) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.other_stays');
        }

        $own = array_filter($this->all($user), static fn (UnifiedInbox $each): bool => false === $each->isOther);

        if (\count($own) <= 1) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.last_stays');
        }

        $other = $this->other($user);

        // Accounts with no other group go to Other; an account in this group
        // and another just stays in the other. Accounts that were in it by
        // default — never put anywhere, and this was the first group — get a
        // row now, or they would quietly move to whichever group becomes
        // first instead of to Other.
        foreach ($this->accountsIn($user, $inbox) as $account) {
            $elsewhere = array_filter($this->groupsOf($user, $account), static fn (UnifiedInbox $each): bool => $each->id !== $inbox->id);

            if ([] === $elsewhere) {
                $this->em->persist(new UnifiedInboxAccount($other, $account));
            }
        }

        $this->changes->destroyed($user, [(int) $inbox->id]);
        $this->changes->updated($user, [(int) $other->id]);

        $this->em->remove($inbox);
        $this->em->flush();
        $this->forget($user);
    }

    /**
     * The reader's rows, with the two starting ones made if they are missing.
     *
     * @param list<UnifiedInbox> $found
     *
     * @return list<UnifiedInbox>
     */
    private function withStartingRows(User $user, array $found): array
    {
        $hasOwn   = false;
        $hasOther = false;

        foreach ($found as $inbox) {
            $hasOther = $hasOther || $inbox->isOther;
            $hasOwn   = $hasOwn || false === $inbox->isOther;
        }

        if (true === $hasOwn && true === $hasOther) {
            return $found;
        }

        $made = [];

        if (false === $hasOwn) {
            $made[] = new UnifiedInbox($user, null, UnifiedInboxIcon::Inbox->value, -1);
        }

        if (false === $hasOther) {
            $made[] = new UnifiedInbox($user, null, UnifiedInboxIcon::OTHER_DEFAULT->value, \count($found) + 1, true);
        }

        foreach ($made as $inbox) {
            $this->em->persist($inbox);
        }

        $this->em->flush();

        // Sorted here rather than read back: the rows are all in hand, and the
        // read would be one more query on the page that made them.
        $all = [...$found, ...$made];

        usort($all, static fn (UnifiedInbox $a, UnifiedInbox $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return $all;
    }

    /** @return list<Account> */
    private function accountsOf(User $user): array
    {
        $key = (int) $user->id;

        if (false === isset($this->accounts[$key])) {
            /** @var list<Account> $accounts */
            $accounts = array_values([...$this->accountRepository->findForUserOrdered($user)]);

            $this->accounts[$key] = $accounts;
        }

        return $this->accounts[$key];
    }

    /** Whether any switched-on account is listed in the inbox. */
    private function holdsActive(User $user, UnifiedInbox $inbox): bool
    {
        $ids    = $this->accountIdsOf($user, $inbox);
        $active = $this->active[(int) $user->id] ?? [];

        foreach ($ids as $accountId) {
            if (true === ($active[$accountId] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function validName(string $name, bool $mayBeEmpty): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ('' === $name) {
            if (true === $mayBeEmpty) {
                return null;
            }

            throw new InvalidUnifiedInboxException('settings.unified_inboxes.name_required');
        }

        if (mb_strlen($name) > UnifiedInbox::NAME_MAX_LENGTH) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.name_too_long');
        }

        return $name;
    }

    /** An icon outside the set is the inbox tray rather than a refusal. */
    private function validIcon(string $icon): string
    {
        return UnifiedInboxIcon::of($icon)->value;
    }

    private function assertOwnAccount(User $user, Account $account): void
    {
        if ($account->usr?->id !== $user->id) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.not_found');
        }
    }

    private function assertOwn(User $user, UnifiedInbox $inbox): void
    {
        if ($inbox->usr->id !== $user->id) {
            throw new InvalidUnifiedInboxException('settings.unified_inboxes.not_found');
        }
    }

    /** After a write: the next question reads the rows again. */
    private function forget(User $user): void
    {
        unset($this->inboxes[(int) $user->id], $this->membership[(int) $user->id]);
    }
}
