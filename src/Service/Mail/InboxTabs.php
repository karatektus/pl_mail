<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Embeddable\CategorySorting;
use App\Entity\User\User;
use App\Repository\Mail\InboxCategoryRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The tabs a reader has: the five built-in ones and the ones they made.
 *
 * One place that knows how a tab key in a URL, a drop target or a posted body
 * becomes a tab, because each of those is untrusted input and each of them used
 * to be `MessageCategory::tryFrom()`. The ownership check lives here too: a
 * custom key resolves only against the categories THIS reader owns, so another
 * person's category, or one that has since been deleted, is simply not a tab.
 *
 * WHAT THIS COSTS, STATED PLAINLY. A key that could not be a custom one —
 * every built-in name, an empty string, a mistyped URL — is answered without a
 * query, and the reader's categories are read at most once per request however
 * many callers ask. But a reader with tabs switched on pays that ONE indexed
 * read on every inbox render and every sidebar-counts poll, whether or not they
 * ever made a category: the strip has to know whether there is a tab of theirs
 * to draw, and only the table can say. The inbox page has a query budget
 * (ThreadListQueryBudgetTest), and that single read is why the inbox's own
 * ceiling is one higher than the other lists'.
 *
 * ORDER AND ON/OFF ARE APPLIED HERE TOO, so no surface draws the strip its
 * own way. Primary first, always; then CategorySorting::$tabOrder; then any tab
 * that list does not name — built-ins in their usual order, the reader's own
 * oldest first. A built-in the reader switched off is not a tab at all: not in
 * the strip, not a drop target, and its key resolves to nothing, exactly like a
 * deleted category's. The settings page is the one place that lists it, through
 * managedFor().
 *
 * Memoised for the request, and resettable for the reason NewMailMarkers is: a
 * worker serves many readers one after another, and a memo that outlived its
 * request would show the first reader's tabs to the second.
 */
final class InboxTabs implements ResetInterface
{
    /** @var array<string, list<InboxTab>> user identifier => their own tabs */
    private array $custom = [];

    public function __construct(
        private readonly InboxCategoryRepository $categories,
    ) {
    }

    public function reset(): void
    {
        $this->custom = [];
    }

    /**
     * The reader's own tabs, in the order they arranged them.
     *
     * @return list<InboxTab>
     */
    public function customFor(UserInterface $user): array
    {
        return $this->custom[$user->getUserIdentifier()] ??= $this->read($user);
    }

    /**
     * Every tab the reader could be shown, in their order, without the built-in
     * ones they switched off.
     *
     * @return list<InboxTab>
     */
    public function allFor(UserInterface $user): array
    {
        $off = self::sorting($user)->switchedOff();

        return array_values(array_filter(
            $this->managedFor($user),
            static fn (InboxTab $tab): bool => null === $tab->builtIn || false === in_array($tab->builtIn, $off, true),
        ));
    }

    /**
     * Every tab there is for this reader, switched off or not, in their order —
     * what the settings page lists, with a switch beside each built-in.
     *
     * @return list<InboxTab>
     */
    public function managedFor(UserInterface $user): array
    {
        $byKey = [];

        foreach (MessageCategory::cases() as $category) {
            $byKey[$category->value] = InboxTab::builtIn($category);
        }

        foreach ($this->customFor($user) as $tab) {
            $byKey[$tab->value] = $tab;
        }

        $ordered = [MessageCategory::Primary->value => $byKey[MessageCategory::Primary->value]];

        foreach (self::sorting($user)->tabOrder as $key) {
            if (true === isset($byKey[$key]) && false === isset($ordered[$key])) {
                $ordered[$key] = $byKey[$key];
            }
        }

        // What the stored order does not name, in the order it was built in
        // above: built-ins first, then the reader's own oldest first. A tab
        // made after the reader last arranged the strip lands at its end.
        return array_values($ordered + $byKey);
    }

    /**
     * The built-in categories the reader switched off. Primary never is.
     *
     * @return list<MessageCategory>
     */
    public function switchedOffFor(UserInterface $user): array
    {
        return self::sorting($user)->switchedOff();
    }

    /**
     * The tab a key names for this reader, or null when it names none of theirs.
     */
    public function resolve(UserInterface $user, string $key): ?InboxTab
    {
        $builtIn = MessageCategory::tryFrom($key);

        if (null !== $builtIn) {
            // Switched off is no tab, like a deleted category: a stale link
            // opens Primary and a drop onto it is refused.
            return true === self::sorting($user)->isSwitchedOff($builtIn) ? null : InboxTab::builtIn($builtIn);
        }

        if (false === InboxTab::looksCustom($key)) {
            return null;
        }

        $names = [];

        foreach ($this->customFor($user) as $tab) {
            $names[(int) $tab->categoryId] = (string) $tab->name;
        }

        return InboxTab::parse($key, $names);
    }

    /** The reader's sorting choices; the shipped ones for anything not a User. */
    private static function sorting(UserInterface $user): CategorySorting
    {
        return $user instanceof User ? $user->categorySorting : new CategorySorting();
    }

    /**
     * @return list<InboxTab>
     */
    private function read(UserInterface $user): array
    {
        $tabs = [];

        foreach ($this->categories->findForUserOrdered($user) as $category) {
            $tabs[] = InboxTab::custom((int) $category->id, (string) $category->name);
        }

        return $tabs;
    }
}
