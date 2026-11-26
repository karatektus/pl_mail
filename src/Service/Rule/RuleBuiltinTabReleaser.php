<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Rule\MailRule;
use App\Infrastructure\Messaging\Message\RecategoriseMessagesMessage;
use App\Repository\Mail\Message\MessageRuleTabRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lets go of the mail a filter filed into a built-in tab, when that filter is
 * deleted or no longer files there (#762).
 *
 * WHY THIS EXISTS AT ALL
 * ──────────────────────
 * A filter's built-in tab is an input to MessageCategorizer
 * (Message::$ruleBuiltinCategory), read before everything else so that no
 * re-sort undoes it. That also means nothing else ever undoes it. A tab of the
 * reader's own has a way out — removing the tab un-files its mail — but a
 * built-in one cannot be removed. So "domain contains .fr → Updates", run over
 * existing mail and then thought better of, would hold thousands of messages
 * in Updates for good, and deleting the filter would change nothing.
 *
 * What is released: the mail this filter filed into a built-in tab it no longer
 * names. A filter that still files into the same tab keeps its mail, whatever
 * else about it changed. Mail released goes back to being sorted like any
 * other (RecategoriseMessagesHandler); a changed filter's new tab applies to
 * mail that arrives from now on, or to existing mail when the reader runs it
 * over that — the same as any other change to a filter.
 *
 * Disabling a filter releases nothing: switched off is not gone, and turning
 * it back on should not need a run over old mail to put it back.
 */
final readonly class RuleBuiltinTabReleaser
{
    /** Messages per envelope. */
    public const int BATCH = 500;

    public function __construct(
        private MessageRuleTabRepository $messages,
        private MessageBusInterface      $bus,
    ) {}

    /** Before the filter is deleted, while the messages still name it. */
    public function releaseDeleted(MailRule $rule): void
    {
        $this->release($rule, null);
    }

    /** After the filter's actions were saved: whatever it no longer files into. */
    public function releaseChanged(MailRule $rule): void
    {
        $this->release($rule, self::builtInTab($rule));
    }

    /**
     * The built-in tab a filter files into, or null. The last one, if a filter
     * somehow names several: that is the one filing leaves on the message.
     */
    public static function builtInTab(MailRule $rule): ?MessageCategory
    {
        $tab = null;

        foreach ($rule->actions as $action) {
            if (true === is_array($action)
                && RuleActionExecutor::FILE_IN_CATEGORY === ($action['type'] ?? null)
                && true === is_string($action['builtIn'] ?? null)) {
                $tab = MessageCategory::tryFrom($action['builtIn']) ?? $tab;
            }
        }

        return $tab;
    }

    private function release(MailRule $rule, ?MessageCategory $keep): void
    {
        $userId = $rule->usr?->id;

        if (null === $rule->id || null === $userId) {
            return;
        }

        $released = $this->messages->releaseFromRule($rule->id, $keep);

        foreach (array_chunk($released, self::BATCH) as $batch) {
            $this->bus->dispatch(new RecategoriseMessagesMessage((int) $userId, $batch));
        }
    }
}
