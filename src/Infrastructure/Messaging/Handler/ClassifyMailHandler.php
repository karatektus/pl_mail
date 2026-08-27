<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\PromptLibrary;
use App\Service\Mail\MessageCategorizer;
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

    public function __construct(
        private MessageRepository      $messages,
        private ContactRepository      $contacts,
        private MessageCategorizer     $categorizer,
        private AiAssistant            $ai,
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

        $touched = false;

        foreach ($this->messages->findByIds($message->messageIds) as $mail) {
            if (false === $this->worthAsking($mail)) {
                continue;
            }

            $verdict = $this->ask($mail);

            // Stamped whether or not the answer was usable — see the docblock.
            $mail->aiCategorisedAt = new DateTimeImmutable();
            $mail->aiCategory      = $verdict;
            $touched               = true;
        }

        if (true === $touched) {
            $this->entityManager->flush();
        }
    }

    /**
     * Only mail that no rule recognised, only once, and only for somebody who
     * still wants this done to their mail.
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
    private function worthAsking(Message $mail): bool
    {
        if (null !== $mail->aiCategorisedAt) {
            return false;
        }

        $user = $mail->account->usr ?? null;

        if (null === $user) {
            return false;
        }

        if (false === $this->permissions->allows($user, AiFeature::Categorise)) {
            return false;
        }

        $explanation = $this->categorizer->explain(
            $mail,
            $this->contacts->findCorrespondentEmails($user),
        );

        return 'default' === $explanation['reason'];
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

        return implode("\n", [
            'From: ' . trim(((string) $mail->fromName) . ' <' . ((string) $mail->fromAddress) . '>'),
            'Subject: ' . trim((string) $mail->subject),
            '',
            mb_substr($body, 0, self::BODY_BUDGET),
        ]);
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
