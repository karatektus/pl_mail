<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\ImapUtf7Helper;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Mailbox;
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
            (int) $binding->account->getId(),
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
        $cacheKey = $label->id . ':' . $account->getId();
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
     * read is Mailbox::getLabel(); this is the only way to write it.
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
        $binding = $this->binding($label, $mailbox->getAccount());

        if ($binding->mailbox === $mailbox) {
            return $binding;
        }

        $previous = $mailbox->getLabelBinding();

        if (null !== $previous && $previous !== $binding) {
            // Released in a flush of its own. Doctrine makes no promise about
            // the order of two updates to the same table in one unit of work,
            // so clearing and claiming together is a coin toss on the unique
            // index — and one that would pass in testing and fail in the
            // field, since the order it happens to pick depends on which
            // entity entered the identity map first.
            $previous->mailbox = null;
            $mailbox->setLabelBinding(null);

            $this->em->flush();
        }

        $binding->mailbox = $mailbox;

        $mailbox->setLabelBinding($binding);

        return $binding;
    }

    public function systemLabel(LabelRole $role, Account $account): Label
    {
        $user   = $account->getUsr();
        $userId = (int) $user->getId();

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

        $this->binding($label, $account);

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

        $user     = $account->getUsr();
        $userId   = (int) $user->getId();
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
     * @return list<string>
     */
    public function segmentsFromImapPath(string $fullPath, ?string $delimiter): array
    {
        if (null === $delimiter || '' === $delimiter) {
            $delimiter = '/';
        }

        $segments = array_map(ImapUtf7Helper::decode(...), explode($delimiter, $fullPath));
        $segments = array_values(array_filter($segments, function (string $segment): bool {
            return '' !== trim($segment);
        }));

        if (count($segments) > 1 && 'INBOX' === strtoupper($segments[0])) {
            array_shift($segments);
        }

        return $segments;
    }
}
