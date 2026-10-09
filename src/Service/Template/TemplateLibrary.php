<?php

declare(strict_types=1);

namespace App\Service\Template;

use App\Domain\DTO\Template\TemplateLocation;
use App\Domain\DTO\Template\TemplateTree;
use App\Domain\DTO\Template\TemplateTreeNode;
use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;
use App\Entity\User\User;
use App\Repository\Template\MailTemplateRepository;
use App\Repository\Template\TemplateFolderRepository;
use App\Service\Mail\UserAccounts;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One user's templates and folders: the tree they form, and every change to
 * where something sits in it.
 *
 * The single writer of `account`, `folder` and `parent`. A place in the tree
 * is two columns that have to agree (see TemplateFolder), so nothing else
 * assigns them: callers name a place by its key, locate() turns that into a
 * TemplateLocation it has checked, and the write methods take only that. The
 * settings page and the composer's "save this draft as a template" both go
 * through here, which is what keeps a folder under one account from ever
 * holding a template filed under another.
 *
 * Nothing here flushes. Each caller is a controller action with its own idea
 * of when the request has succeeded.
 */
final readonly class TemplateLibrary
{
    public function __construct(
        private MailTemplateRepository   $templates,
        private TemplateFolderRepository $folders,
        private UserAccounts             $accounts,
        private EntityManagerInterface   $em,
    ) {
    }

    public function tree(User $user): TemplateTree
    {
        $folders   = $this->folders->findForUser($user);
        $templates = $this->templates->findForUser($user);

        $accountNodes = [];

        foreach ($this->accounts->all($user) as $account) {
            $accountNodes[] = $this->node(
                new TemplateLocation($account),
                (string) ($account->displayAddress ?? $account->email ?? $account->name),
                '',
                0,
                $folders,
                $templates,
            );
        }

        $folderNodes = [];

        foreach ($folders as $folder) {
            if (null === $folder->account && null === $folder->parent) {
                $folderNodes[] = $this->node(new TemplateLocation(null, $folder), $folder->name, '', 0, $folders, $templates);
            }
        }

        return new TemplateTree(
            array_values(array_filter(
                $templates,
                static fn (MailTemplate $template): bool => null === $template->account && null === $template->folder,
            )),
            $accountNodes,
            $folderNodes,
        );
    }

    /**
     * The place a key names, or null when it names nothing of this user's.
     *
     * Null rather than "the top level" for a key that does not resolve: the
     * key came from a form, and quietly filing a template somewhere other than
     * where the form said is worse than refusing the request.
     */
    public function locate(User $user, string $key): ?TemplateLocation
    {
        if (TemplateLocation::ROOT === $key || '' === $key) {
            return new TemplateLocation();
        }

        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        $id = (int) $id;

        if ('account' === $kind) {
            foreach ($this->accounts->all($user) as $account) {
                if ($id === $account->id) {
                    return new TemplateLocation($account);
                }
            }

            return null;
        }

        if ('folder' === $kind) {
            $folder = $this->folders->find($id);

            return null !== $folder && $folder->usr === $user
                ? new TemplateLocation($folder->account, $folder)
                : null;
        }

        return null;
    }

    /** Where a template is now. */
    public function locationOf(MailTemplate $template): TemplateLocation
    {
        return new TemplateLocation($template->account, $template->folder);
    }

    public function place(MailTemplate $template, TemplateLocation $location): void
    {
        $template->account = $location->account;
        $template->folder  = $location->folder;
    }

    /** A new folder inside `$parent`, which may be the top level or an account. */
    public function createFolder(User $user, string $name, TemplateLocation $parent): TemplateFolder
    {
        $folder          = new TemplateFolder();
        $folder->usr     = $user;
        $folder->name    = $name;
        $folder->account = $parent->account;
        $folder->parent  = $parent->folder;

        $this->em->persist($folder);

        return $folder;
    }

    /**
     * Remove a folder and every folder inside it; the templates they held move
     * up to where the folder was.
     *
     * The move is done here, by hand, although the foreign key would rescue
     * the templates on its own. It would rescue them to the wrong place: SET
     * NULL clears `folder_id` and nothing else, so a template three folders
     * deep in an account would land directly under the account even when the
     * deleted folder had a parent it could have moved into. Deleting "2024"
     * out of "Invoices" should leave its templates in "Invoices".
     *
     * The subfolders are left to the database's cascade.
     */
    public function deleteFolder(TemplateFolder $folder): void
    {
        $doomed = $this->withDescendants($folder, $this->folders->findBy(['usr' => $folder->usr]));

        foreach ($this->templates->findInFolders($doomed) as $template) {
            $template->folder = $folder->parent;
        }

        // Before the delete, in its own flush: the templates must already point
        // elsewhere when the row goes, or the SET NULL gets there first.
        $this->em->flush();
        $this->em->remove($folder);
    }

    /** A second copy beside the first, for "like that one, but…". */
    public function duplicate(MailTemplate $template, string $name): MailTemplate
    {
        $copy          = new MailTemplate();
        $copy->usr     = $template->usr;
        $copy->name    = $name;
        $copy->subject = $template->subject;
        $copy->body    = $template->body;

        $this->place($copy, $this->locationOf($template));
        $this->em->persist($copy);

        return $copy;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<TemplateFolder> $folders   every folder of the user
     * @param list<MailTemplate>   $templates every template of the user
     */
    private function node(
        TemplateLocation $location,
        string           $name,
        string           $parentPath,
        int              $depth,
        array            $folders,
        array            $templates,
    ): TemplateTreeNode {
        $path     = '' === $parentPath ? $name : sprintf('%s / %s', $parentPath, $name);
        $children = [];

        foreach ($folders as $folder) {
            // Directly inside this node: same parent folder, and — for the
            // folders with no parent — the same account, which is what tells
            // an account's first level from the top level's.
            if ($folder->parent === $location->folder
                && (null !== $location->folder || $folder->account === $location->account)
                && $folder !== $location->folder) {
                $children[] = $this->node(
                    new TemplateLocation($folder->account, $folder),
                    $folder->name,
                    $path,
                    $depth + 1,
                    $folders,
                    $templates,
                );
            }
        }

        return new TemplateTreeNode(
            $location,
            $name,
            $path,
            $depth,
            $children,
            array_values(array_filter(
                $templates,
                static fn (MailTemplate $template): bool => $template->folder === $location->folder
                    && (null !== $location->folder || $template->account === $location->account),
            )),
        );
    }

    /**
     * @param list<TemplateFolder> $all
     *
     * @return list<TemplateFolder>
     */
    private function withDescendants(TemplateFolder $folder, array $all): array
    {
        $found = [$folder];

        foreach ($all as $candidate) {
            if ($candidate->parent === $folder) {
                array_push($found, ...$this->withDescendants($candidate, $all));
            }
        }

        return $found;
    }
}
