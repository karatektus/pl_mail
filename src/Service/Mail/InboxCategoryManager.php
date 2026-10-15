<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\AppLocale;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Exception\InvalidInboxCategoryException;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MessageThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Making, renaming and removing the inbox tabs a reader made themselves.
 *
 * NAMES ARE CHECKED HERE, NOT BY A CONSTRAINT. Per reader and without regard to
 * case, which an index cannot say in Doctrine attributes (see InboxCategory),
 * and a built-in tab's name is refused outright: a strip with two tabs both
 * called Promotions, or one a reader typed beside the real one, is a strip
 * nobody can trust. The built-ins are refused by every name they go by: the
 * value the enum stores and the label each interface language shows, since a
 * German reader's "Werbung" beside the built-in Werbung is the same two tabs
 * called the same thing — and a household where one person reads German and
 * another English shares an installation, not a language.
 *
 * REMOVAL DOES WHAT THE DATABASE CANNOT. The two columns that file mail under a
 * category are ON DELETE SET NULL, so the foreign keys un-file the mail and the
 * conversations on their own. They cannot clear a PIN. A conversation the reader
 * dragged into the category carries MessageThread::$categoryPinnedAt, every
 * recompute skips a pinned thread, and once its category was gone it would
 * stay pinned to nothing for ever — filed in no tab of the reader's, never
 * resolved again, and nothing on screen to say why. So the pins of everything
 * in the category are cleared first, in the same transaction.
 *
 * A thread pinned to a BUILT-IN tab, or to another of the reader's categories,
 * is not touched: it was never pinned to this one.
 */
final readonly class InboxCategoryManager
{
    public function __construct(
        private InboxCategoryRepository $categories,
        private MessageThreadRepository $threads,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private \App\Jmap\State\StateManager $stateManager,
        private InboxTabChanges $tabChanges,
    ) {
    }

    /**
     * @throws InvalidInboxCategoryException
     */
    public function create(User $owner, string $name): InboxCategory
    {
        $category           = new InboxCategory();
        $category->usr      = $owner;
        $category->name     = $this->validName($owner, $name, null);

        $this->em->persist($category);
        $this->em->flush();

        // The id exists only now, and so does the key a phone knows it by.
        $this->tabChanges->created($owner, [$category->tabKey()]);
        $this->em->flush();

        return $category;
    }

    /**
     * @throws InvalidInboxCategoryException
     */
    public function rename(InboxCategory $category, string $name): void
    {
        $category->name = $this->validName($category->usr, $name, $category);

        $this->tabChanges->updated($category->usr, [$category->tabKey()]);
        $this->em->flush();
    }

    /**
     * Remove the category, un-file everything in it, free the pins into it, and
     * put the conversations it held back in the tab they belong in.
     */
    public function remove(InboxCategory $category): void
    {
        $this->em->wrapInTransaction(function () use ($category): void {
            $connection = $this->em->getConnection();

            // Recorded while the id is still there to be named.
            $this->tabChanges->destroyed($category->usr, [$category->tabKey()]);

            // Its key leaves the stored order too, so the order never names
            // a tab that is gone — InboxTabs would skip it, but a restore or
            // a later category could otherwise read it.
            $category->usr->categorySorting->arrange(array_values(array_diff(
                $category->usr->categorySorting->tabOrder,
                [$category->tabKey()],
            )));

            // Taken BEFORE the delete, because the delete is what empties the
            // column this is read from.
            $held = array_map(
                intval(...),
                $connection->fetchFirstColumn(
                    'SELECT id FROM message_thread WHERE user_category_id = :id',
                    ['id' => $category->id],
                ),
            );

            $connection->executeStatement(
                'UPDATE message_thread SET category_pinned_at = NULL WHERE user_category_id = :id',
                ['id' => $category->id],
            );

            // The foreign keys un-file the rows; what is already loaded in this
            // request still points at the category, and the next flush would
            // try to write the reference back. Mirrored here.
            $loaded = $this->em->getUnitOfWork()->getIdentityMap();

            foreach ($loaded[MessageThread::class] ?? [] as $thread) {
                if ($thread instanceof MessageThread && $thread->userCategory === $category) {
                    $thread->userCategory = null;
                }
            }

            foreach ($loaded[Message::class] ?? [] as $message) {
                if ($message instanceof Message && $message->ruleCategory === $category) {
                    $message->ruleCategory = null;
                }
            }

            $this->em->remove($category);
            $this->em->flush();

            // Freed is not the same as resolved. A conversation dragged into the
            // category kept whatever built-in category it had when it was
            // dragged, and with its pin gone nothing would look at it again
            // until its next message — it would sit in a stale tab for days, in
            // the one place this removal was meant to put right. The foreign
            // keys have already emptied the verdicts, so this resolves each of
            // them from its messages like any other conversation.
            // And told: a JMAP client shows the reader's own tabs too, and
            // these conversations have just left one.
            foreach ($this->threads->recomputeCategoriesForThreads($held) as $accountId => $moved) {
                $this->stateManager->recordThreadsTouched($accountId, $moved);
            }

            $this->em->flush();
        });
    }

    /** A built-in tab's stored value, or its label in any interface language. */
    private function isBuiltInName(string $name): bool
    {
        $wanted = mb_strtolower($name);

        foreach (MessageCategory::cases() as $category) {
            if ($wanted === $category->value) {
                return true;
            }

            foreach (AppLocale::cases() as $locale) {
                $label = $this->translator->trans('tabs.' . $category->value, [], 'messages', $locale->value);

                if ($wanted === mb_strtolower($label)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function validName(User $owner, string $name, ?InboxCategory $renaming): string
    {
        // Unicode whitespace both ends: a name pasted with a non-breaking space
        // would otherwise be a tab nobody can tell from its neighbour.
        $name = (string) preg_replace('/^\s+|\s+$/u', '', $name);

        if ('' === $name) {
            throw new InvalidInboxCategoryException('settings.categories.error.empty');
        }

        if (mb_strlen($name) > InboxCategory::NAME_MAX_LENGTH) {
            throw new InvalidInboxCategoryException('settings.categories.error.too_long');
        }

        if (true === $this->isBuiltInName($name)) {
            throw new InvalidInboxCategoryException('settings.categories.error.reserved');
        }

        foreach ($this->categories->findForUserOrdered($owner) as $existing) {
            if ($existing === $renaming) {
                continue;
            }

            if (mb_strtolower($existing->name) === mb_strtolower($name)) {
                throw new InvalidInboxCategoryException('settings.categories.error.taken');
            }
        }

        return $name;
    }
}
