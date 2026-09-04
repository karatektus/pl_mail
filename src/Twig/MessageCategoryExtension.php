<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Service\Mail\MessageCategorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Every answer there is about which tab a message belongs in, for the details
 * popover.
 *
 * ONE FUNCTION RETURNING EVERYTHING, RATHER THAN ONE PER ANSWER
 * ─────────────────────────────────────────────────────────────
 * There were two — `category_explanation` and `category_explanation_local` —
 * and the template decided between them with a condition of its own
 * (`reason == 'gmail'`). That put the rule for WHICH answers are worth showing
 * in the markup, one `{% if %}` away from the rules that produce them, and it
 * meant the panel could only ever show two of the four things there are to say.
 * A caller that wants a comparison should be handed the comparison.
 *
 * WHAT THE FOUR ANSWERS ARE, AND WHY EACH IS SEPARATELY INTERESTING
 * ────────────────────────────────────────────────────────────────
 *   effective  what actually decided this message's tab, whatever that was —
 *              a Gmail label, a header, a known correspondent, the model, or
 *              nothing at all.
 *   rules      what plMail's own deterministic cascade says with no model and
 *              no provider label in the way. This is the one that takes over
 *              the day a mailbox leaves Gmailify, and until then it never runs,
 *              so it is worth being able to look at before that day rather than
 *              after it.
 *   ai         what a language model said, if one was asked. Stored beside the
 *              decision and never inside it — see Message::$aiCategory — so
 *              this can disagree with `effective` without anything having moved.
 *   pinned     when somebody put this conversation in its category by hand, in
 *              which case none of the above is where the mail actually is. The
 *              tab is theirs and the rest of the panel is the reasoning it
 *              overrode.
 *
 * Recomputed from the same class that decided it in the first place rather than
 * read from a column: a stored reason would be written by whatever version of
 * the rules ran at sync time, and would quietly go on explaining a decision the
 * current rules no longer make.
 *
 * The correspondent check is per-address rather than the whole set the
 * categoriser is normally handed — one message is being explained, not a
 * mailbox classified.
 */
final class MessageCategoryExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageCategorizer $categorizer,
        private readonly ContactRepository  $contacts,
        private readonly Security           $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('category_report', $this->report(...)),
        ];
    }

    /**
     * @return array{
     *     filed: string|null,
     *     filedMessage: string|null,
     *     effective: array{category: string, reason: string, signal: string|null},
     *     gmail: string|null,
     *     rules: array{category: string, reason: string, signal: string|null},
     *     ai: array{category: string|null, asked: bool},
     *     source: string,
     *     pinnedAt: \DateTimeImmutable|null,
     * }|null null when there is nobody to answer for
     */
    public function report(Message $message): ?array
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return null;
        }

        $from = mb_strtolower(trim((string) $message->fromAddress));

        // Looked up once and handed to both calls. It is a database read, and
        // the two answers must agree about who this person writes to or the
        // comparison is between two different questions.
        $correspondents = $this->contacts->isCorrespondent($user, $from) ? [$from => true] : [];

        $sorting = $user->categorySorting;

        return [
            // WHERE IT ACTUALLY IS — off the THREAD, which is the row the
            // tabs are a filter over.
            //
            // Two mistakes have been made here and this is the second one. The
            // first was recomputing it: everything else in this array is a
            // recomputation, which is the right shape for "what would each of
            // them say" and the wrong shape for "where is this mail", and the
            // panel announced "Updates — Gmail said so" about a message sitting
            // in Primary. So it was read off the row instead.
            //
            // It was read off the WRONG row. The mailbox lists threads and
            // filters on `thread.category`; `message.category` is an input to
            // that, resolved most-recent-wins across the conversation and
            // overridden entirely by a pin. On any thread with more than one
            // message the two are free to differ, and when they do it is the
            // thread that decides which tab the reader is looking at. A report
            // filed from this panel said `filed:updates` about a conversation
            // sitting in Primary, which is a true statement about a column
            // nobody can see.
            //
            // Falls back to the message for a message not yet threaded.
            'filed'     => ($message->thread->category ?? $message->category)?->value,

            // The message's own row, and only when it disagrees with the
            // thread. Not decoration: a disagreement means the conversation is
            // being decided by a sibling message or a pin rather than by
            // anything on this one, which is the difference between a rule to
            // fix and nothing to fix at all.
            'filedMessage' => null !== $message->thread?->category
                && $message->thread->category !== $message->category
                    ? $message->category?->value
                    : null,

            // The same cascade the reader's own settings produce, so the reason
            // beside the category above is the reason for THAT category.
            'effective' => $this->describe(
                $message,
                $correspondents,
                $sorting->overrideProvider,
                $sorting->ignoresAi(),
                $sorting->assistantFirst(),
            ),

            // Gmail's own answer, on its own line, because it is one of the
            // three things a reader is comparing and it was only ever visible
            // as a footnote on the line above — which vanished entirely once
            // somebody chose to overrule it. Null on any other account: not
            // "Primary", which would be an answer Gmail never gave.
            'gmail'     => null === $message->gmailLabelIds
                ? null
                : MessageCategory::fromGmailLabels($message->gmailLabelIds)->value,

            // Both flags, and they are not the same exclusion. `ignoreAi` takes
            // the model's opinion out; `ignoreProviderLabels` takes Gmail's out.
            // "The rules" means plMail's own cascade, which is neither.
            'rules'     => $this->describe($message, $correspondents, true, true),
            'ai'        => [
                'category' => $message->aiCategory?->value,
                // Asked and answered, asked and got nothing, never asked — the
                // panel says something different for each, and only the stamp
                // can tell the middle case from the last.
                'asked'    => null !== $message->aiCategorisedAt,
            ],

            // Which of the three is in force, so the panel can mark it rather
            // than leaving the reader to infer it from three categories and
            // their own memory of a settings page.
            'source'    => $sorting->source,
            'pinnedAt'  => $message->thread?->categoryPinnedAt,
        ];
    }

    /**
     * @param array<string,true> $correspondents
     *
     * @return array{category: string, reason: string, signal: string|null}
     */
    private function describe(
        Message $message,
        array $correspondents,
        bool $ignoreProviderLabels,
        bool $ignoreAi,
        bool $aiFirst = false,
    ): array {
        $explanation = $this->categorizer->explain(
            $message,
            $correspondents,
            $ignoreProviderLabels,
            $ignoreAi,
            $aiFirst,
        );

        return [
            'category' => $explanation['category']->value,
            'reason'   => $explanation['reason'],
            'signal'   => $explanation['signal'],
        ];
    }
}
