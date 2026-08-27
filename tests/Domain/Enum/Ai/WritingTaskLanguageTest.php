<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Ai;

use App\Domain\Enum\Ai\WritingTask;
use PHPUnit\Framework\TestCase;

/**
 * Every writing task is told to answer in the language it was written to.
 *
 * The instructions are in English, and a model reads that as evidence of the
 * language it is meant to reply in — so a German mail came back with an English
 * reply, and proofreading a German draft quietly translated it. The second is
 * the serious one: a task asked to fix punctuation returned a different message
 * in a different language, and the writer would have had to notice before
 * sending it.
 *
 * Pinned per case rather than once, because the prompts are four separate
 * strings in a match arm and a fifth task added later is exactly the one that
 * would be written without it.
 */
final class WritingTaskLanguageTest extends TestCase
{
    /** Every task, so a new one cannot be added without the rule. */
    public function testEveryTaskIsToldToKeepTheLanguageOfTheMessage(): void
    {
        foreach (WritingTask::cases() as $task) {
            $prompt = $task->systemPrompt();

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

    /** The rule is added to the task's own words, not instead of them. */
    public function testTheTaskStillSaysWhatItIsFor(): void
    {
        self::assertStringContainsString('You draft replies', WritingTask::Reply->systemPrompt());
        self::assertStringContainsString('You correct email', WritingTask::Proofread->systemPrompt());
        self::assertStringContainsString('You shorten email', WritingTask::Shorten->systemPrompt());
        self::assertStringContainsString('register of email', WritingTask::Formal->systemPrompt());
    }
}
