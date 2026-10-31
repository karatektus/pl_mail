<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Enum\Mail\CategoryFiling;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Repository\Mail\InboxCategoryRepository;
use App\Service\Imap\MessageThreader;
use Psr\Log\LoggerInterface;

/**
 * Files a message under one of the reader's own categories, when a filter's
 * "File in category" action says to.
 *
 * A FILTER IS THE LAST WORD
 * ─────────────────────────
 * The reader wrote it, naming the mail it is for, so it outranks every other
 * way a conversation gets a tab: the built-in rules, the language model and
 * Gmail's own categories (whatever CategorySorting::$overrideProvider says
 * about the latter two — both are guesses, and a filter is not).
 *
 * It used to file only when the receiving server vouched for the sender
 * (SenderAuthentication), so that a forged "mondialrelay.fr" could not sit in a
 * "Shipping" tab beside the real notices. That made the feature fail where it
 * is needed most, silently: the reader's own sent mail has no verdict at all,
 * and an application sent through a recruiting platform is signed by the
 * platform, not by the company in its From line. A filter applied to existing
 * mail filed two thirds of what it matched (#405). The verdict still guards the
 * calendar (EventReconciler), where mail acts without being asked; a tab only
 * decides where mail is listed, and forged mail is what Spam is for — a filter
 * leaves Spam alone unless it names it (RuleConditions).
 *
 * What it does not cover: the conversation. A tab holds conversations, and a
 * conversation follows its newest received message (MessageThreader), so a
 * newer message no filter claimed takes the conversation out of the tab again.
 * The reader's own replies do not: see MessageThreader::adoptCategory().
 *
 * Filing only ever chooses a tab — it never touches labels, the Spam folder,
 * read state or the authentication warning the message view shows.
 *
 * Two filters matching one message: the one that runs last decides, because the
 * column is single-valued and this overwrites. "Stop after this filter" is the
 * existing way to make an earlier match final.
 */
final readonly class RuleCategoryFiler
{
    public function __construct(
        private InboxCategoryRepository $categories,
        private MessageThreader         $threader,
        private LoggerInterface         $logger,
    ) {}

    /**
     * @param array<string,mixed> $action the stored {type, categoryId} entry
     */
    public function file(MailRule $rule, Message $message, array $action): CategoryFiling
    {
        [$outcome, $category] = $this->decide($rule, $message, $action, log: true);

        if (CategoryFiling::Filed !== $outcome || null === $category) {
            return $outcome;
        }

        $message->ruleCategory = $category;

        $thread = $message->thread;

        if (null !== $thread) {
            // The same most-recent-wins, pin and hold rules the built-in
            // category follows, rather than a second copy of them here.
            $this->threader->refreshCategory($message, $thread);
        }

        return CategoryFiling::Filed;
    }

    /**
     * What file() would answer, without filing anything — for "Test my
     * filters on this message", which has to say why a tab stayed empty
     * using the same reasons the filing itself uses.
     *
     * @param array<string,mixed> $action
     */
    public function check(MailRule $rule, Message $message, array $action): CategoryFiling
    {
        return $this->decide($rule, $message, $action, log: false)[0];
    }

    /**
     * @param array<string,mixed> $action
     *
     * @return array{0: CategoryFiling, 1: ?InboxCategory}
     */
    private function decide(MailRule $rule, Message $message, array $action, bool $log): array
    {
        $categoryId = $action['categoryId'] ?? null;

        if (false === is_int($categoryId)) {
            return [CategoryFiling::NoCategory, null];
        }

        $category = $this->categories->find($categoryId);

        // Re-checked at run time, not trusted from the stored action: whatever
        // is in the json, a rule must never reach a category its owner does not
        // own, and a deleted one is simply nothing to file under. The same
        // discipline as labels and integrations in RuleActionExecutor.
        if (null === $category || null === $rule->usr || $category->usr !== $rule->usr) {
            if (true === $log) {
                $this->logger->warning('RuleCategoryFiler: category not found for rule owner', [
                    'ruleId'     => $rule->id,
                    'categoryId' => $categoryId,
                ]);
            }

            return [CategoryFiling::NoCategory, null];
        }

        return [CategoryFiling::Filed, $category];
    }
}
