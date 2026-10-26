<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\ImapUtf7Helper;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Jmap\State\JmapObjectType;
use DateTimeImmutable;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central find-or-create for labels. Both sync layers use this:
 *   - MailboxSyncer maps IMAP folders onto labels (system role or nested
 *     custom chain derived from the folder path).
 *   - GmailLabelSyncer maps Gmail API labels onto the same model, splitting
 *     Gmail's "Work/Invoices" naming into a parent chain.
 *
 * Two steps, always in this order: resolve the user-level Label, then resolve
 * its LabelBinding for the account being synced. Callers pass an Account
 * because they are inside an account-scoped sync loop and because the binding
 * is what carries the provider ids — but the Label they get back is shared
 * across every account of that user. Two accounts syncing a folder called
 * "Receipts" converge on one Label with two bindings.
 *
 * Name uniqueness per (usr, parent) is enforced here as well as by a partial
 * unique index.
 *
 * Caches entity IDs (never entities) so long-running handlers survive
 * em->clear() / flush() cycles.
 */
final class LabelResolver
{
    /** @var array<int, array<string, int>> userId → role-value → labelId */
    private array $roleIdCache = [];

    /** @var array<int, array<string, int>> userId → fullName → labelId */
    private array $pathIdCache = [];

    /** @var array<string, int> "labelId:accountId" → bindingId */
    private array $bindingIdCache = [];

    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly LabelBindingRepository $bindingRepository,
        private readonly EntityManagerInterface $em,
        private readonly StateManager           $stateManager,
    ) {}

    /**
     * A new binding is a new JMAP Mailbox — JMAP identifies Mailboxes per
     * account, so the binding is the thing with a JMAP id, not the Label.
     * This is the single choke point for both system and custom labels, so
     * recording here covers every sync path that mints one. The row is
     * persisted but not flushed; it commits on the caller's next flush.
     */
    private function recordCreated(LabelBinding $binding): void
    {
        $this->stateManager->recordCreated(
            (int) $binding->account->id,
            JmapObjectType::Mailbox,
            (string) $binding->id,
        );
    }

    /**
     * Find-or-create the binding of a label on an account. Idempotent, and
     * safe to call for a label that already has one.
     */
    public function binding(Label $label, Account $account): LabelBinding
    {
        $cacheKey = $label->id . ':' . $account->id;
        $cachedId = $this->bindingIdCache[$cacheKey] ?? null;

        if (null !== $cachedId) {
            $binding = $this->em->find(LabelBinding::class, $cachedId);

            if (null !== $binding) {
                return $binding;
            }
        }

        $binding = $this->bindingRepository->findOneForLabelAndAccount($label, $account);

        if (null === $binding) {
            $binding = new LabelBinding();
            $binding->label = $label;
            $binding->account = $account;

            $this->em->persist($binding);
            $this->em->flush();

            $this->recordCreated($binding);
        }

        $this->bindingIdCache[$cacheKey] = (int) $binding->id;

        return $binding;
    }

    /**
     * Point a label's binding at the IMAP folder that feeds it. The inverse
     * read is Mailbox::$label; this is the only way to write it.
     *
     * A folder that already feeds a different label is moved, not copied.
     * label_binding.mailbox_id is unique — a folder feeds exactly one label —
     * so claiming it without releasing it first is a constraint violation, and
     * it takes the whole folder sync down with it rather than skipping one
     * folder.
     *
     * That was unreachable until a folder could resolve to a different name
     * than it did last time, which is precisely what decoding modified UTF-7
     * did: the first sync after that change re-resolves "INBOX.Entw&APw-rfe"
     * from the label it created before ("Entw&APw-rfe") to the one it should
     * always have had ("Entwürfe"), and without this every account with a
     * non-ASCII folder would have failed its next sync outright. A renamed
     * folder reaches the same path and always could have.
     */
    public function bindMailbox(Label $label, Mailbox $mailbox): LabelBinding
    {
        $binding = $this->binding($label, $mailbox->account);

        if ($binding->mailbox === $mailbox) {
            return $binding;
        }

        $previous = $mailbox->labelBinding;

        if (null !== $previous && $previous !== $binding) {
            // Released in a flush of its own. Doctrine makes no promise about
            // the order of two updates to the same table in one unit of work,
            // so clearing and claiming together is a coin toss on the unique
            // index — and one that would pass in testing and fail in the
            // field, since the order it happens to pick depends on which
            // entity entered the identity map first.
            $previous->mailbox = null;
            $mailbox->labelBinding = null;

            $this->em->flush();
        }

        $binding->mailbox = $mailbox;

        $mailbox->labelBinding = $binding;

        return $binding;
    }

    public function systemLabel(LabelRole $role, Account $account): Label
    {
        $label = $this->userSystemLabel($role, $account->usr);

        $this->binding($label, $account);

        return $label;
    }

    /**
     * The user's label for a role, created if missing — and bound to no
     * account, which is the difference from systemLabel().
     *
     * For a role no account has anything to do with. Unread and All Mail are
     * views over every account at once; a binding would make it a JMAP Mailbox on each of
     * them, empty for ever, and give the provider pushes a label id to go
     * looking for.
     */
    public function userSystemLabel(LabelRole $role, User $user): Label
    {
        $userId = (int) $user->id;

        $cachedId = $this->roleIdCache[$userId][$role->value] ?? null;
        $label    = null;

        if (null !== $cachedId) {
            $label = $this->em->find(Label::class, $cachedId);
        }

        if (null === $label) {
            $label = $this->labelRepository->findOneByRoleForUser($role, $user);
        }

        if (null === $label) {
            $label            = new Label();
            $label->usr       = $user;
            $label->name      = $role->displayName();
            $label->role      = $role;
            $label->sortOrder = $role->sortOrder();
            $label->isVisible = $role->isVisible();

            $this->em->persist($label);
            $this->em->flush();
        }

        $this->roleIdCache[$userId][$role->value] = (int) $label->id;

        return $label;
    }

    /**
     * Find-or-create a nested custom label chain and return the leaf.
     *
     * Only the leaf gets a binding: intermediate labels are structure, and
     * Gmail/IMAP both address the leaf by its full path anyway.
     *
     * @param list<string> $segments  e.g. ['Work', 'Invoices']
     */
    public function customChain(array $segments, Account $account): ?Label
    {
        $segments = array_values(array_filter($segments, function (string $segment): bool {
            return '' !== trim($segment);
        }));

        if (count($segments) === 0) {
            return null;
        }

        $user     = $account->usr;
        $userId   = (int) $user->id;
        $fullName = implode('/', $segments);
        $cachedId = $this->pathIdCache[$userId][$fullName] ?? null;

        if (null !== $cachedId) {
            $label = $this->em->find(Label::class, $cachedId);

            if (null !== $label) {
                $this->binding($label, $account);

                return $label;
            }
        }

        $parent = null;
        $label  = null;

        foreach ($segments as $segment) {
            $label = $this->labelRepository->findOneChildByName($user, $parent, $segment);

            if (null === $label) {
                $label         = new Label();
                $label->usr    = $user;
                $label->parent = $parent;
                $label->name   = $segment;

                $this->em->persist($label);
                $this->em->flush();
            }

            $parent = $label;
        }

        $this->pathIdCache[$userId][$fullName] = (int) $label->id;

        $this->binding($label, $account);

        return $label;
    }

    /**
     * Give an IMAP folder its label, the way it should appear in the sidebar,
     * without taking a label away from a folder that already has one.
     *
     * A special-use folder feeds its system label, always. Every other folder
     * is a place the user made, and the rule is the one #221 agreed on: plMail
     * mirrors the account's folder tree and never quietly reshapes it.
     *
     * This used to re-derive the label from the folder's path on every sync —
     * split the path, find-or-create a chain, bind — and that is what made a
     * folder impossible to own:
     *
     *   - renaming its label in plMail was undone by the next sync, which
     *     found-or-created a label under the old name and moved the folder
     *     there, leaving the renamed one empty;
     *   - two folders whose paths differ only by the INBOX namespace,
     *     "Work" and "INBOX.Work", both resolved to the label "Work", and as a
     *     folder feeds exactly one label the two took it from each other on
     *     alternate syncs.
     *
     * So the path is read once, when the folder first arrives, and again only
     * when the folder itself moved ($previousPath differs: renamed on the
     * server, by plMail or by another client) — and a folder renamed where it
     * stands keeps its label under the new name (renameInPlace()). A folder
     * whose path has not changed keeps the label it has. One exception, for a label that is the
     * raw modified UTF-7 of its name ("B&APw-cher") — what syncs before
     * names were decoded left behind, and never what anybody typed.
     *
     * A new folder whose label is already another folder's, on this account,
     * keeps its INBOX segment instead ("INBOX › Work"), so the two stay two.
     */
    public function linkFolder(Mailbox $mailbox, Account $account, ?string $previousPath = null): void
    {
        $specialUse = $mailbox->specialUse;

        if (null !== $specialUse) {
            $this->bindMailbox($this->systemLabel(LabelRole::fromSpecialUse($specialUse), $account), $mailbox);

            return;
        }

        $current = $mailbox->labelBinding?->label;
        $moved   = null !== $previousPath && $previousPath !== $mailbox->fullPath;

        if (null !== $current && null === $current->role && false === $moved && false === self::isUndecoded($current)) {
            return;
        }

        if (true === $moved && null !== $current && null === $current->role
            && true === $this->renameInPlace($current, $mailbox, $account, (string) $previousPath)
        ) {
            return;
        }

        $segments = $this->segmentsFromImapPath((string) $mailbox->fullPath, $mailbox->delimiter);

        if (count($segments) === 0) {
            $segments = [(string) $mailbox->name];
        }

        if (true === $this->feedsAnotherFolder($segments, $account, $mailbox)) {
            $full = $this->segmentsFromImapPath((string) $mailbox->fullPath, $mailbox->delimiter, keepInbox: true);

            if ($full !== $segments) {
                $segments = $full;
            }
        }

        $label = $this->customChain($segments, $account);

        if (null !== $label) {
            $this->bindMailbox($label, $mailbox);
        }
    }

    /**
     * A folder renamed where it stands takes its label along: same label, new
     * name, so whatever was filed under it is still filed under it and the
     * old name does not linger in the sidebar as an empty label.
     *
     * Only when the label is this folder's alone. A label other accounts have
     * too is theirs as well, and renaming it from here would rename their
     * folder in the sidebar; one whose new name a sibling already has would
     * collide. Both, and a folder that moved to another parent, are linked
     * afresh instead, the way a new folder is.
     */
    private function renameInPlace(Label $label, Mailbox $mailbox, Account $account, string $previousPath): bool
    {
        $delimiter = (string) $mailbox->delimiter;
        $path      = (string) $mailbox->fullPath;

        if ('' === $delimiter || self::parentOf($previousPath, $delimiter) !== self::parentOf($path, $delimiter)) {
            return false;
        }

        foreach ($label->bindings as $binding) {
            if ($binding->account !== $account) {
                return false;
            }
        }

        $segments = $this->segmentsFromImapPath($path, $delimiter);
        $name     = (string) end($segments);
        $sibling  = $this->labelRepository->findOneChildByName($account->usr, $label->parent, $name);

        if ('' === trim($name) || (null !== $sibling && $sibling !== $label)) {
            return false;
        }

        $label->name = $name;

        // The id cache is keyed by the full name, which just changed.
        unset($this->pathIdCache[(int) $account->usr->id]);

        return true;
    }

    private static function parentOf(string $path, string $delimiter): string
    {
        $cut = strrpos($path, $delimiter);

        return false === $cut ? '' : substr($path, 0, $cut);
    }

    /**
     * Whether the label these segments name already feeds a different folder
     * of this account. Looked up without creating anything: a chain made only
     * to be found taken would be a label nobody asked for.
     *
     * @param list<string> $segments
     */
    private function feedsAnotherFolder(array $segments, Account $account, Mailbox $mailbox): bool
    {
        $label = null;

        foreach ($segments as $segment) {
            $label = $this->labelRepository->findOneChildByName($account->usr, $label, $segment);

            if (null === $label) {
                return false;
            }
        }

        $fed = $label?->bindingFor($account)?->mailbox;

        return null !== $fed && $fed !== $mailbox;
    }

    private static function isUndecoded(Label $label): bool
    {
        $name = (string) $label->name;

        return str_contains($name, '&') && ImapUtf7Helper::decode($name) !== $name;
    }

    /**
     * Split an IMAP folder full path into label segments, honouring the
     * account delimiter and stripping a leading INBOX namespace segment
     * (Courier/Dovecot-style "INBOX.Work.Invoices").
     *
     * The path arrives raw, in modified UTF-7, and stays raw where it is
     * stored — see Mailbox::$fullPath and ImapUtf7Helper. These segments are
     * not stored: they become Label::$name, which is the string the sidebar
     * renders. That is the whole reason for decoding them here, and the reason
     * the bug was invisible from the syncer's side. Mailbox::$name has been
     * correct all along (webklex decodes it), templates just never showed it,
     * so a German account's sidebar read "Entw&APw-rfe" and "Gel&APY-schte
     * Objekte" while the right names sat unused in the next column.
     *
     * Decoded per segment rather than over the whole path, because the
     * delimiter is not part of any segment's encoding — which is exactly how
     * webklex derives Folder::$full_name from Folder::$path.
     *
     * `$keepInbox` is for linkFolder()'s one collision case and nothing else.
     *
     * @return list<string>
     */
    public function segmentsFromImapPath(string $fullPath, ?string $delimiter, bool $keepInbox = false): array
    {
        if (null === $delimiter || '' === $delimiter) {
            $delimiter = '/';
        }

        $segments = array_map(ImapUtf7Helper::decode(...), explode($delimiter, $fullPath));
        $segments = array_values(array_filter($segments, function (string $segment): bool {
            return '' !== trim($segment);
        }));

        if (false === $keepInbox && count($segments) > 1 && 'INBOX' === strtoupper($segments[0])) {
            array_shift($segments);
        }

        return $segments;
    }
}
