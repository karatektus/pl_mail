<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Label\LabelBinding\LabelBindingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The plMail label for a label on an account's server that has an id of its
 * own: a Gmail label, an Outlook category, a mailbox on a JMAP server (#691).
 *
 * Found by that id first, and by name only when the account has never had
 * it. The syncs used to go by name alone (LabelResolver::customChain()), so a
 * label renamed in Gmail came back as a second label under the new name, the
 * old one stayed, and both bindings carried the same id. It also bound a
 * label split off on one account (#690) back to the label with that name on
 * the next sync.
 *
 * A label renamed or moved on the server is renamed or moved for that
 * account the way a rename made under that account in plMail is
 * (LabelSplitter::renameOnAccount()): in place where the label is on this
 * account alone, split off where other accounts have it too, joined to the
 * label already at that name where there is one. The server is not told,
 * because the rename came from there. IMAP folders have their own rule for
 * the same thing (LabelResolver::linkFolder()), because a folder's id is its
 * path.
 *
 * Duplicates the old way left behind are repaired on the way: several
 * bindings on the account with the same id are merged into the oldest, whose
 * label keeps its rules and notification switches, and that one then takes
 * the server's name. A label left with nothing on it is deleted.
 */
final readonly class RemoteLabelLinker
{
    public function __construct(
        private LabelResolver          $resolver,
        private LabelSplitter          $splitter,
        private LabelBindingRepository $bindings,
        private LabelRepository        $labels,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * The label for this remote id, under the server's current name, with
     * the id recorded on its binding.
     *
     * @param 'gmailLabelId'|'graphCategoryId'|'jmapMailboxId' $field    the binding column the id lives in
     * @param list<string>                                     $segments the server's name, split at each level
     */
    public function link(string $field, string $remoteId, array $segments, Account $account): ?Label
    {
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => '' !== trim($segment)));

        if ([] === $segments) {
            return null;
        }

        $known = '' === $remoteId ? [] : $this->bindings->findAllByRemoteId($field, $remoteId, $account);

        if ([] === $known) {
            $label = $this->resolver->customChain($segments, $account);

            if (null !== $label && '' !== $remoteId) {
                self::record($this->resolver->binding($label, $account), $field, $remoteId);
            }

            return $label;
        }

        $keeper = array_shift($known);

        foreach ($known as $duplicate) {
            $this->merge($duplicate, $keeper->label, $account);
        }

        return $this->follow($keeper->label, $segments, $account);
    }

    /**
     * Rename or move the label for this account to where the server has it.
     *
     * @param non-empty-list<string> $segments
     */
    private function follow(Label $label, array $segments, Account $account): Label
    {
        if ($label->fullName === implode('/', $segments)) {
            return $label;
        }

        $name   = (string) array_pop($segments);
        $parent = [] === $segments ? null : $this->resolver->path($segments, $account->usr);

        // Moved under itself ("Work" renamed to "Work/Old"): the parent found
        // by name is the label, or one under it, and putting it there would
        // be a loop. Left as it is; the next rename on the server settles it.
        if (null !== $parent && true === self::isWithin($parent, $label)) {
            $this->logger->warning('RemoteLabelLinker: a label renamed under itself on the server is left as it is', [
                'accountId' => $account->id,
                'labelId'   => $label->id,
            ]);

            return $label;
        }

        if (false === $this->splitter->canMove($label, $account, $parent, $name)) {
            $this->logger->warning('RemoteLabelLinker: the server\'s new name is another label on this account', [
                'accountId' => $account->id,
                'labelId'   => $label->id,
            ]);

            return $label;
        }

        $followed = $this->splitter->renameOnAccount($label, $account, $name, $parent, $label->color, tellServer: false);

        // Its path cache is keyed by full name, and a name just changed.
        $this->resolver->forgetPaths($account->usr);

        return $followed;
    }

    /**
     * A second binding of the same remote id on the account, from before
     * #691: its mail on this account goes to the label kept, and it goes.
     */
    private function merge(LabelBinding $duplicate, Label $into, Account $account): void
    {
        $from = $duplicate->label;

        if ($from === $into) {
            return;
        }

        $this->labels->moveAccountMessagesAndThreads($account, $from, $into);

        $from->removeBinding($duplicate);
        $this->em->remove($duplicate);
        $this->em->flush();

        if (0 === count($from->bindings) && 0 === count($from->children)) {
            $from->parent?->removeChild($from);
            $this->em->remove($from);
            $this->em->flush();
        }

        $this->logger->info('RemoteLabelLinker: merged a label the server had renamed into the one it was', [
            'accountId' => $account->id,
            'from'      => $from->id,
            'into'      => $into->id,
        ]);
    }

    private static function isWithin(Label $label, Label $ancestor): bool
    {
        for ($current = $label; null !== $current; $current = $current->parent) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** @param 'gmailLabelId'|'graphCategoryId'|'jmapMailboxId' $field */
    private static function record(LabelBinding $binding, string $field, string $remoteId): void
    {
        match ($field) {
            'gmailLabelId'    => $binding->gmailLabelId = $remoteId,
            'graphCategoryId' => $binding->graphCategoryId = $remoteId,
            'jmapMailboxId'   => $binding->jmapMailboxId = $remoteId,
        };
    }
}
