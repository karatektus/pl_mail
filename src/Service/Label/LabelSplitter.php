<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\DTO\Push\NotifyScope;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Rule\MailRule\MailRuleRepository;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Renaming a label on one account alone (#690).
 *
 * A label is the user's and can be on several accounts; a LabelBinding is the
 * label on one account's server. Two accounts with a "Sports" label of their
 * own share one plMail label, because the sync finds labels by name. Renamed
 * from the merged list, the label is renamed on every account, and that stays
 * so. Renamed from under one account, only that account's binding moves: to a
 * new label with the new name, taking that account's mail with it, while the
 * other accounts keep the label as it was. From then on the two are separate
 * labels, in both views.
 *
 * The other way works too, and has to: a name that another label already has
 * joins that label, as long as it is not on this account yet. Split "Flights"
 * into "Flights2" and rename it back, and the binding and its mail return to
 * "Flights"; "Flights2", empty, is deleted. It used to be refused as a name
 * that was taken, which left a split that could not be undone.
 *
 * The binding moves rather than being recreated, so the account's remote id
 * goes with it and the rename reaches the server as a rename, for that
 * account alone (LabelStructurePropagator::renamedOn()); and so its id, which is the JMAP
 * Mailbox id, stays the one the apps already know.
 *
 * Labels under it on the same account follow, so the account's tree keeps its
 * shape: one that is on this account only is re-parented, one that is on
 * other accounts as well is split the same way. Each that moved is renamed on
 * the server, because Gmail spells the parent into every child's name.
 *
 * Personal settings that named the old label for this account move with it: a
 * rule limited to this account that applies it, and a notification switch for
 * it, which is turned on for the new label too. A rule for every account keeps
 * the old label, which is still the label everywhere else — unless the old
 * label was emptied and deleted, and then every rule that applied it moves.
 *
 * Rejected: renaming in place and recording a per-account name on the binding.
 * The label would still be one label — one colour, one unread count, one row
 * in the merged list — and every name lookup (paths, search, the sync) would
 * have to know about a second name.
 */
final readonly class LabelSplitter
{
    public function __construct(
        private LabelRepository          $labels,
        private MailRuleRepository       $rules,
        private EntityManagerInterface   $em,
        private LabelStructurePropagator $structure,
        private MailChangeRecorder       $changes,
    ) {
    }

    /**
     * Whether renaming this label for this account would split it rather than
     * rename it: it is on this account and on at least one other.
     */
    public function splits(Label $label, Account $account): bool
    {
        return null !== $label->bindingFor($account) && count($label->bindings) > 1;
    }

    /**
     * The label already at that name and place, other than this one — the one
     * a rename for this account would join rather than make (null when there
     * is none, and the rename makes or renames one).
     */
    public function joins(Label $label, ?Label $parent, string $name): ?Label
    {
        return null === $label->usr ? null : $this->labels->findNameConflict($label->usr, $parent, $name, $label);
    }

    /**
     * Whether this account's part of the label can move to that name: the
     * label already there, if any, is not on this account yet — and the same
     * holds for every label under it that has to follow. A label is on an
     * account once, so two can join only where they never met.
     */
    public function canMove(Label $label, Account $account, ?Label $parent, string $name): bool
    {
        $into = $this->joins($label, $parent, $name);

        if (null === $into) {
            return true;
        }

        if (null !== $label->bindingFor($account) && null !== $into->bindingFor($account)) {
            return false;
        }

        foreach ($label->children as $child) {
            if (true === $this->isOnAccountBelow($child, $account)
                && false === $this->canMove($child, $account, $into, (string) $child->name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Give this account's binding of the label a new name and place, and
     * return the label it is on afterwards: the label already at that name,
     * which it joins (renaming "Flights2" back to "Flights"), or one made for
     * it, or the label itself renamed where it is on this account alone. The
     * caller has checked canMove().
     *
     * A label left with no binding and nothing under it is deleted: what was
     * split off and has been joined back has nothing left to stand for.
     */
    public function renameOnAccount(Label $label, Account $account, string $name, ?Label $parent, ?string $color): Label
    {
        /** @var list<array{Label, string}> $renamed */
        $renamed = [];

        $target = $this->moveTo($label, $account, $parent, $name, $color, $renamed);

        $this->em->flush();

        // For this account only. The label it joined may be on others, and
        // they already carry this name.
        foreach ($renamed as [$moved, $previousFullName]) {
            $this->structure->renamedOn($moved, $account, $previousFullName);
            $this->changes->labelChanged($moved);
        }

        $emptied = $label !== $target && 0 === count($label->bindings) && 0 === count($label->children);

        $this->carryPersonalSettings($label, $target, $account, $emptied);

        if (true === $emptied) {
            $label->parent?->removeChild($label);
            $this->em->remove($label);
        }

        $this->em->flush();

        return $target;
    }

    /**
     * @param list<array{Label, string}> $renamed every label that moved, with its full name before
     */
    private function moveTo(Label $from, Account $account, ?Label $parent, string $name, ?string $color, array &$renamed): Label
    {
        $into = $this->joins($from, $parent, $name);

        // On this account and nowhere else, all the way down, and nothing to
        // join: it can move as it is. Anything below it on another account
        // keeps it a split, or re-parenting it would rename that account's
        // labels too.
        if (null === $into && true === $this->onlyOn($from, $account)) {
            $before = $this->fullNamesOnAccount($from, $account);

            $from->name   = $name;
            $from->parent = $parent;
            $from->color  = $color;

            foreach ($before as [$moved, $previousFullName]) {
                $renamed[] = [$moved, $previousFullName];
            }

            return $from;
        }

        $previousFullName = $from->fullName;
        $target           = $into ?? $this->newLabel($from, $parent, $name, $color);
        $binding          = $from->bindingFor($account);

        if (null !== $binding) {
            $from->removeBinding($binding);
            $binding->label = $target;

            $this->em->flush();
            $this->labels->moveAccountMessagesAndThreads($account, $from, $target);

            $renamed[] = [$target, $previousFullName];
        }

        foreach ($from->children->toArray() as $child) {
            if (true === $this->isOnAccountBelow($child, $account)) {
                $this->moveTo($child, $account, $target, (string) $child->name, $child->color, $renamed);
            }
        }

        return $target;
    }

    private function newLabel(Label $from, ?Label $parent, string $name, ?string $color): Label
    {
        $label            = new Label();
        $label->usr       = $from->usr;
        $label->parent    = $parent;
        $label->name      = $name;
        $label->color     = $color;
        $label->isVisible = $from->isVisible;
        $label->sortOrder = $from->sortOrder;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    /**
     * Whether this label and every label under it are on this account alone,
     * or on none.
     */
    private function onlyOn(Label $label, Account $account): bool
    {
        foreach ($label->bindings as $binding) {
            if ($binding->account !== $account) {
                return false;
            }
        }

        foreach ($label->children as $child) {
            if (false === $this->onlyOn($child, $account)) {
                return false;
            }
        }

        return true;
    }

    /** Whether this label, or one under it, is on this account. */
    private function isOnAccountBelow(Label $label, Account $account): bool
    {
        if (null !== $label->bindingFor($account)) {
            return true;
        }

        foreach ($label->children as $child) {
            if (true === $this->isOnAccountBelow($child, $account)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The label and every label under it that is on this account, each with
     * its full name now — read before anything is renamed.
     *
     * @return list<array{Label, string}>
     */
    private function fullNamesOnAccount(Label $label, Account $account): array
    {
        $names = null === $label->bindingFor($account) ? [] : [[$label, $label->fullName]];

        foreach ($label->children as $child) {
            array_push($names, ...$this->fullNamesOnAccount($child, $account));
        }

        return $names;
    }

    /**
     * $all when the old label is going: then every rule that applies it moves,
     * whichever account it is for, or it would point at nothing.
     */
    private function carryPersonalSettings(Label $from, Label $to, Account $account, bool $all): void
    {
        if ($from === $to) {
            return;
        }

        $rules = true === $all && $from->usr instanceof User
            ? $this->rules->findForUserOrdered($from->usr)
            : $this->rules->findBy(['account' => $account]);

        foreach ($rules as $rule) {
            $actions = $rule->actions;
            $changed = false;

            foreach ($actions as $index => $action) {
                if (true === is_array($action) && ($action['labelId'] ?? null) === $from->id) {
                    $actions[$index]['labelId'] = $to->id;
                    $changed                    = true;
                }
            }

            if (true === $changed) {
                $rule->actions = $actions;
            }
        }

        $user = $from->usr;

        if ($user instanceof User && true === $user->notifiesFor(NotifyScope::label((int) $from->id))) {
            $user->notifyScopes = [...$user->notifyScopes, NotifyScope::label((int) $to->id)];
        }
    }
}
