<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\MessageCategorizer;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\PromptLibrary;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asks a language model about the mail the ordinary rules could not place.
 *
 * This is the only place in the application that puts an Ollama request on the
 * path of an arriving message, and it is deliberately as far from the arrival
 * as it can be: a worker, after the transaction, on a batch, for a subset.
 *
 * WHAT IT REFUSES TO ASK ABOUT
 * ────────────────────────────
 * Anything a rule already recognised. If the cascade produced a reason other
 * than its default, the answer is already known, is already explicable, and
 * would be DISCARDED even if the model disagreed — the verdict is read only
 * where the cascade fell through. See MessageCategorizer::explain(). Asking
 * anyway would spend seconds per message to store something nothing reads.
 *
 * That check needs the correspondent set, which is why it happens here rather
 * than in the step that dispatched: it is a query, and the step runs inside a
 * sync holding a mailbox connection.
 *
 * FAILURE IS NORMAL AND IS RECORDED AS HAVING HAPPENED
 * ───────────────────────────────────────────────────
 * The host is a box on somebody's network. It may be off, mid-update, or
 * holding a different model than it was last week. None of that is an
 * application error and none of it may fail the job — but the attempt is
 * stamped anyway, so a later backfill can tell "asked, got nothing" from "never
 * asked" and does not retry the same unanswerable message forever.
 */
#[AsMessageHandler]
final readonly class ClassifyMailHandler
{
    /**
     * Body characters sent to the model.
     *
     * Enough for the shape of a message to be obvious — who it is from, what it
     * opens with, whether it is a receipt or a person writing — and short
     * enough that a slow model stays usable across a batch. Nothing below the
     * first screen of an email changes what KIND of email it is.
     */
    private const int BODY_BUDGET = 1200;

    // No MessageCategorizer and no ContactRepository any more. Both were here
    // to answer "did the deterministic cascade fall through?", which is the
    // question worthAsking() stopped needing to ask — see its docblock. The
    // correspondent lookup was a query per batch, so dropping it is a small
    // saving as well as one fewer collaborator.
    public function __construct(
        private MessageRepository       $messages,
        private MessageThreadRepository $threads,
        private ContactRepository       $contacts,
        private MessageCategorizer      $categorizer,
        private AiAssistant             $ai,
        private AiPermissions          $permissions,
        private PromptLibrary          $prompts,
        private EntityManagerInterface $entityManager,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ClassifyMailMessage $message): void
    {
        // Checked again, and not redundantly: settings can change between
        // dispatch and delivery, and a job already on the queue when somebody
        // switches this off must not still run.
        if (false === $this->ai->isEnabledFor(AiFeature::Categorise)) {
            return;
        }

        if ([] === $message->messageIds) {
            return;
        }

        $touched = [];

        foreach ($this->messages->findByIds($message->messageIds) as $mail) {
            if (false === $this->worthAsking($mail, $message->force)) {
                continue;
            }

            $verdict = $this->ask($mail);

            // Stamped whether or not the answer was usable — see the docblock.
            $mail->aiCategorisedAt = new DateTimeImmutable();
            $mail->aiCategory      = $verdict;
            $touched[]             = $mail;
        }

        if ([] === $touched) {
            return;
        }

        $this->refile($touched);
    }

    /**
     * Put the mail we just asked about where the answer says it belongs.
     *
     * WITHOUT THIS THE VERDICT NEVER REACHES A TAB. Nothing else recomputes a
     * category once ingest has written one: PostIngestPipeline files a message
     * as it arrives, this handler runs afterwards and asynchronously, and until
     * now it stored the answer and stopped. While sorting was rules-only that
     * was invisible — the verdict was a tie-break nobody could see. It stopped
     * being invisible the day sorting became a setting: somebody chooses the
     * assistant, new mail keeps arriving into whatever the rules said, and the
     * choice appears to do nothing at all.
     *
     * Scoped to the threads actually touched rather than to the account,
     * because this runs after every batch of arriving mail and the per-account
     * statement scans every message the account has.
     *
     * The reader's own preference decides, exactly as everywhere else: a
     * verdict stored for somebody sorting by rules moves nothing, which is what
     * choosing the rules means.
     *
     * @param list<Message> $mail
     */
    private function refile(array $mail): void
    {
        $threadIds      = [];
        $correspondents = [];

        foreach ($mail as $row) {
            $user = $row->account->usr;

            if (null === $user) {
                continue;
            }

            $userId = (int) $user->id;

            // One lookup per mailbox rather than per message: a batch is
            // usually one account's arriving mail, and this is a query.
            $correspondents[$userId] ??= $this->contacts->findCorrespondentEmails($user);

            $row->category = $this->categorizer->categorize(
                $row,
                $correspondents[$userId],
                $user->categorySorting,
            );

            if (null !== $row->thread?->id) {
                $threadIds[(int) $row->thread->id] = true;
            }
        }

        // Flushed BEFORE the threads are resolved, because that resolution is
        // raw SQL reading the very columns written above.
        $this->entityManager->flush();

        $this->threads->recomputeCategoriesForThreads(array_keys($threadIds));
    }

    /**
     * Every message, once, for anybody who still wants this done to their mail.
     *
     * IT USED TO BE ONLY THE MAIL NO RULE RECOGNISED — `'default' === reason`,
     * which is where the verdict is actually consulted. That was the cheaper
     * thing to do and it made the feature impossible to judge: the model was
     * asked exactly where the rules had already given up, so there was never a
     * message with both a rule's answer and a model's answer to compare, and
     * "is the classifier any good" had no evidence behind it either way.
     *
     * Asking about everything is what puts both answers on the same mail. The
     * message details panel shows them side by side, which is the whole point:
     * a disagreement is now a thing to look at.
     *
     * NOTHING ABOUT WHERE MAIL LANDS CHANGES, and that is not a happy accident
     * — it is why this is safe. MessageCategorizer::explain() reads the stored
     * verdict at its tie-break and nowhere else, so a message a rule matched
     * keeps that rule's answer no matter what the model says about it. The
     * extra calls buy a second opinion on record, not a second decider.
     *
     * The cost is real and worth stating: one model call per message rather
     * than per unrecognised message, on the same host the composer and the
     * search index already queue behind. It is bounded by the three checks
     * below — the installation switch, the person's own, and aiCategorisedAt,
     * which is stamped whether or not the answer was usable, so no message is
     * ever asked about twice.
     *
     * The per-user check goes HERE rather than at dispatch, and that is a scope
     * decision rather than an oversight: one ClassifyMailMessage carries ids
     * from several accounts and therefore several owners, so narrowing at
     * dispatch would silence everybody in a batch that contained one opt-out.
     * ClassifyMailStep keeps the installation-wide check for the reason its own
     * docblock gives — without it every install with the feature off enqueues a
     * job per batch for a handler whose body is `return`.
     *
     * Asked BEFORE explain(), so an opted-out message also saves the
     * findCorrespondentEmails() query that check needs.
     */
    private function worthAsking(Message $mail, bool $force): bool
    {
        // The stamp means "already asked", which is the right reason to skip on
        // ingest and the wrong one when the ANSWER is what has gone stale.
        if (false === $force && null !== $mail->aiCategorisedAt) {
            return false;
        }

        $user = $mail->account->usr ?? null;

        if (null === $user) {
            return false;
        }

        return $this->permissions->allows($user, AiFeature::Categorise);
    }

    /**
     * One question, one word back.
     *
     * The prompt names the closed set and asks for nothing else, and the answer
     * is matched against that set rather than parsed: a model that explains
     * itself, answers in another language, or wraps the word in punctuation is
     * the normal case, not an exception. Anything unrecognisable becomes null,
     * which puts the message back where the rules left it.
     *
     * THE PROMPT IS NO LONGER A LITERAL HERE. It was, and it was the last of the
     * seven still inlined at its call site; an administrator can now replace it
     * from Admin → AI, so the wording comes from PromptLibrary and the shipped
     * text — with the warning about what interpret() below needs from it — lives
     * in {@see \App\Domain\Ai\PromptRules::CATEGORISE}.
     *
     * forCategorisation() and not forTask(): this is the one prompt that gets no
     * language rule appended, because the answer wanted is one English token and
     * not prose. See the library.
     */
    private function ask(Message $mail): ?MessageCategory
    {
        $answer = $this->ai->chat(
            AiFeature::Categorise,
            [
                [
                    'role'    => 'system',
                    'content' => $this->prompts->forCategorisation(),
                ],
                [
                    'role'    => 'user',
                    'content' => $this->describe($mail),
                ],
            ],
            // Low, because this is a classification and not a piece of writing.
            // A model given room to be creative here invents categories.
            temperature: 0.0,
        );

        if (null === $answer) {
            return null;
        }

        return $this->interpret($answer);
    }

    private function describe(Message $mail): string
    {
        $body = trim((string) ($mail->bodyText ?? ''));

        if ('' === $body) {
            // No text part. The subject and sender are still a great deal, and
            // stripping HTML here would mean shipping a parser into a handler
            // that has one job.
            $body = '(no plain text part)';
        }

        return implode("\n", array_filter([
            'From: ' . trim(((string) $mail->fromName) . ' <' . ((string) $mail->fromAddress) . '>'),
            'Subject: ' . trim((string) $mail->subject),
            self::bulkLine($mail),
            '',
            mb_substr($body, 0, self::BODY_BUDGET),
        ], static fn (string $line): bool => '' !== $line));
    }

    /**
     * The headers that say "this was sent to a list", named for the model.
     *
     * WITHOUT THIS THE PROMPT ASKS AN IMPOSSIBLE QUESTION. It tells the model to
     * decide first whether the mail is bulk, and names an unsubscribe link as
     * the sign — and then this method used to hand over a sender, a subject and
     * a body, with every header stripped. The one piece of evidence that
     * marketing cannot fake its way around was the piece being withheld.
     *
     * What that cost, measured on qwen3:4b-instruct: a newsletter with a human
     * sender name, the reader's first name in the subject and second-person
     * prose came back `primary`. Both the old prompt and a rewritten one got it
     * wrong, because neither could see what made it bulk. With this line, both
     * get it right — the fix was never the wording.
     *
     * NAMES ONLY, NOT VALUES. `list-unsubscribe` is the fact; the URL it points
     * at is a tracking link with an account identifier in it, and there is no
     * reason to send that to a model to be told what it already says by
     * existing. It also keeps the line to a handful of tokens.
     *
     * The same header names MessageCategorizer reads, so the two halves of this
     * feature are looking at the same evidence and can be compared on the same
     * mail — which is the entire point of the details panel showing both.
     */
    private static function bulkLine(Message $mail): string
    {
        $headers = array_change_key_case($mail->headers ?? [], CASE_LOWER);

        // ONLY THE HEADERS THAT MEAN "SENT TO MANY", and the ones left out are
        // the point.
        //
        // feedback-id and auto-submitted were here and had to go. They are
        // deliverability and automation markers: an ESP stamps feedback-id on
        // everything it sends, transactional mail included. Paired with a
        // prompt that says bulk is never primary, that line filed a recruiter
        // asking an applicant for missing documents under Promotions — a
        // one-to-one letter, from a named person, expecting a reply, sent
        // through an HR system that happens to use an ESP.
        //
        // What is left is the set that actually implies a mailing: an
        // unsubscribe mechanism, a list id, an explicit bulk precedence.
        // Marketing is legally obliged to carry the first of those, which is
        // why its ABSENCE beside a feedback-id is itself evidence — the mail is
        // machine-sent and not mass-sent.
        $present = array_values(array_filter(
            ['list-unsubscribe', 'list-id', 'precedence'],
            static fn (string $name): bool => '' !== trim((string) ($headers[$name] ?? '')),
        ));

        return [] === $present ? '' : 'Bulk headers: ' . implode(', ', $present);
    }

    /**
     * A category from whatever the model actually said.
     *
     * Matched by containment against the closed set rather than by equality:
     * "Promotions." and "This is promotions mail" are both the model getting it
     * right in a format the prompt asked it not to use, and refusing them would
     * throw away correct answers over punctuation.
     *
     * Ambiguity loses. If two names appear the model has not answered the
     * question, and null is the honest result — it leaves the message where the
     * rules put it rather than picking whichever matched first.
     */
    private function interpret(string $answer): ?MessageCategory
    {
        $lowered = mb_strtolower($answer);
        $found   = null;

        foreach (MessageCategory::cases() as $category) {
            if (false === str_contains($lowered, $category->value)) {
                continue;
            }

            if (null !== $found) {
                $this->logger->info('ClassifyMailHandler: ambiguous answer', ['answer' => mb_substr($answer, 0, 120)]);

                return null;
            }

            $found = $category;
        }

        if (null === $found) {
            $this->logger->info('ClassifyMailHandler: unrecognised answer', ['answer' => mb_substr($answer, 0, 120)]);
        }

        return $found;
    }
}
