<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Ai;

use App\Domain\Enum\Ai\PromptSlot;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\PromptLibrary;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every writing task is told to answer in the language it was written to —
 * whatever that instruction has been reworded to say.
 *
 * WHY THIS TEST CHANGED SHAPE, AND WHAT IT NOW REFUSES TO ASSERT
 * ─────────────────────────────────────────────────────────────
 * It used to call WritingTask::systemPrompt() four times and look for the
 * literal substrings "language of the message" and "never switch to English" in
 * each. That was the right test while the sentence was a constant: four match
 * arms could be edited one at a time, and a fifth task added later was exactly
 * the one that would be written without the rule.
 *
 * An administrator can now edit that sentence in Admin → AI. Asserting those
 * English substrings against the prompt that is SENT would therefore have been
 * a test asserting that nobody had used the feature — it would go red on a
 * German installation that had translated its own prompts, which is a correct
 * use of the page, while staying green on the failure it was written for. A
 * test that fires on legitimate configuration and not on the bug is worse than
 * no test: it gets deleted, and the protection goes with it.
 *
 * So the file was split along the line between what the code guarantees and
 * what this release happens to say.
 *
 *   THE STRUCTURE is ours and is not configurable. PromptLibrary appends the
 *   language rule to every task, so whatever the rule says, every task carries
 *   it — and that assertion is STRONGER than the old one, because it now also
 *   catches an administrator's edit failing to reach the composer at all, which
 *   the substring version could not have seen.
 *
 *   THE WORDS are this release's, and are pinned where they live: on
 *   PromptSlot::Language->shipped(). Once, not four times — four assertions
 *   over one constant is four copies of one fact, and the old file only needed
 *   them because the sentence was concatenated into four separate strings.
 *
 *   THE FALLBACK is what makes emptying the box safe. There is no way to
 *   REMOVE the rule, only to reword it: an empty override is an absent one, so
 *   clearing the field restores our wording rather than sending a task with
 *   nothing on the end of it. That is the regression this rule was written for
 *   — a German mail answered in English, and PROOFREADING A GERMAN DRAFT
 *   TRANSLATING IT, which destroys the text it was asked to correct and has to
 *   be caught by the writer before they press send.
 *
 * A KernelTestCase now rather than a plain unit test, because "what is in
 * force" is a database question. The one-row ai_settings table is emptied and
 * written directly here; nothing else in the suite depends on its contents.
 */
final class WritingTaskLanguageTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private PromptLibrary $prompts;
    private AiSettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);
        $this->prompts    = $container->get(PromptLibrary::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** Every task, so a new one cannot be added without the rule. */
    public function testEveryTaskCarriesTheLanguageRuleAsShipped(): void
    {
        foreach (WritingTask::cases() as $task) {
            $prompt = $this->prompts->forTask($task);

            self::assertStringContainsString(
                'language of the message',
                $prompt,
                sprintf('%s does not say which language to answer in', $task->value),
            );

            // The clause that does the work. Without it the rule is competing
            // with an entire prompt written in English and usually loses.
            self::assertStringContainsString(
                'never switch to English',
                $prompt,
                sprintf('%s does not refuse the pull toward English', $task->value),
            );
        }
    }

    /**
     * And every task carries the ADMINISTRATOR'S rule once there is one.
     *
     * The assertion the old file could not make, and the one that matters now:
     * the append is structural. A task whose prompt was assembled from the
     * shipped constant while the settings page showed a different sentence
     * would be a tuned prompt with no effect on drafting, reported by nothing.
     */
    public function testAnAdministratorsWordingReachesEveryTask(): void
    {
        $this->override(PromptSlot::Language, 'Antworte immer in der Sprache der Nachricht.');

        foreach (WritingTask::cases() as $task) {
            $prompt = $this->prompts->forTask($task);

            self::assertStringContainsString(
                'Antworte immer in der Sprache der Nachricht.',
                $prompt,
                sprintf('%s was assembled without the language rule that is in force', $task->value),
            );

            self::assertStringNotContainsString(
                'never switch to English',
                $prompt,
                sprintf('%s carries BOTH the shipped rule and the replacement', $task->value),
            );
        }
    }

    /**
     * Emptying the box puts the rule back, and cannot delete it.
     *
     * Whitespace as well as '', because a textarea that has been selected and
     * deleted often keeps a newline, and a stored "\n" that counted as an
     * override would be a task whose language rule is a blank line.
     */
    public function testClearingTheLanguageRuleRestoresTheShippedWordingRatherThanRemovingIt(): void
    {
        foreach (['', '   ', "\n\n"] as $emptied) {
            $this->override(PromptSlot::Language, $emptied);

            self::assertSame(
                PromptSlot::Language->shipped(),
                $this->prompts->language(),
                'an emptied language rule must fall back to the shipped wording, not to nothing',
            );

            foreach (WritingTask::cases() as $task) {
                self::assertStringContainsString('never switch to English', $this->prompts->forTask($task));
            }
        }
    }

    /** The rule is added to the task's own words, not instead of them. */
    public function testTheTaskStillSaysWhatItIsFor(): void
    {
        self::assertStringContainsString('You draft replies', $this->prompts->forTask(WritingTask::Reply));
        self::assertStringContainsString('You correct email', $this->prompts->forTask(WritingTask::Proofread));
        self::assertStringContainsString('You shorten email', $this->prompts->forTask(WritingTask::Shorten));
        self::assertStringContainsString('register of email', $this->prompts->forTask(WritingTask::Formal));
    }

    /**
     * The words this release ships, pinned once.
     *
     * Here rather than four times over four assembled prompts: the sentence is
     * one constant now, and the reason the old file repeated itself was that it
     * was reading four separately concatenated strings.
     */
    public function testTheShippedRuleStillNamesTheFailureItWasWrittenFor(): void
    {
        $shipped = PromptSlot::Language->shipped();

        self::assertStringContainsString('language of the message', $shipped);
        self::assertStringContainsString('Never translate the message', $shipped);
        self::assertStringContainsString('never switch to English', $shipped);
    }

    /**
     * Categorisation gets NO language rule, and that is deliberate.
     *
     * Its answer is one English token from a closed set that
     * ClassifyMailHandler::interpret() matches against; telling the model to
     * answer a German mail in German is telling it to answer "Werbung", which
     * is the model getting it right in a spelling nothing can read.
     */
    public function testCategorisationIsNotToldToAnswerInTheLanguageOfTheMail(): void
    {
        self::assertStringNotContainsString('never switch to English', $this->prompts->forCategorisation());
    }

    private function override(PromptSlot $slot, string $text): void
    {
        $settings = $this->settings->current() ?? new AiSettings();
        $settings->prompts->put($slot, $text);

        $this->em->persist($settings);
        $this->em->flush();
        $this->em->clear();
    }
}
