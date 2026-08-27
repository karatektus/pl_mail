<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Ai\PromptSlot;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiSettings;
use App\Entity\Embeddable\AiPrompts;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\PromptLibrary;
use App\Service\Ai\ThreadSummariser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the model is actually told, once an administrator can change it.
 *
 * THE SUBJECT OF THIS FILE IS THE STALENESS KEY
 * ─────────────────────────────────────────────
 * Everything else here is ordinary fallback behaviour. The part worth a test is
 * the one that was a hand-bumped integer until this feature existed:
 * `thread_summary.prompt_version`, whose constant said "bumped whenever
 * SYSTEM_PROMPT below changes". Nobody bumps a constant when an administrator
 * edits a prompt in Admin → AI, so every summary already stored would have gone
 * on being displayed as current under instructions nobody uses any more — on a
 * feature whose whole risk is being confidently wrong about mail the reader is
 * not going to check.
 *
 * The key is now a fingerprint of the prompt that was actually sent, so the
 * invalidation is a property of the text rather than of somebody's memory. The
 * tests below are the four gestures an administrator can make — edit it, edit
 * the language rule that is appended to it, clear it, retype exactly what was
 * there — and what each one does to that fingerprint.
 *
 * The one-row ai_settings table is emptied and written directly, inside a
 * transaction that is rolled back.
 */
final class PromptLibraryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;
    private PromptLibrary $prompts;
    private ThreadSummariser $summariser;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);
        $this->prompts    = $container->get(PromptLibrary::class);
        $this->summariser = $container->get(ThreadSummariser::class);

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

    /** Nothing configured is the ordinary state, and it sends what we ship. */
    public function testWithNothingOverriddenEverySlotSendsTheShippedText(): void
    {
        foreach (PromptSlot::cases() as $slot) {
            self::assertNull($this->prompts->override($slot));
            self::assertSame($slot->shipped(), $this->prompts->text($slot));
        }
    }

    public function testAnOverrideReplacesTheShippedTextRatherThanBeingAddedToIt(): void
    {
        $this->override(PromptSlot::Reply, 'You draft replies in limerick form.');

        $prompt = $this->prompts->forTask(WritingTask::Reply);

        self::assertStringContainsString('limerick', $prompt);
        self::assertStringNotContainsString('Match the tone of the message', $prompt);
    }

    /**
     * THE ONE THIS FEATURE WOULD HAVE BEEN WORSE THAN NOTHING WITHOUT.
     *
     * Edit the summary prompt and every summary already on file has to stop
     * being shown. Nothing is bumped, nothing is remembered — the fingerprint
     * moves because the string moved.
     */
    public function testEditingTheSummaryPromptChangesTheFingerprintThatStoredSummariesAreFiledUnder(): void
    {
        $before = $this->summariser->promptFingerprint();

        $this->override(PromptSlot::Summary, 'You summarise email. Two sentences, no more.');

        self::assertNotSame(
            $before,
            $this->summariser->promptFingerprint(),
            'an edited summary prompt left every stored summary looking current',
        );
    }

    /**
     * And so does editing the LANGUAGE rule, which is appended to it.
     *
     * The subtle half of the same trap: the summary prompt itself is untouched,
     * so anything keyed on that text alone would have missed this — and a
     * summary written under "answer in the language of the mail" is a different
     * summary from one written without it, in the most visible way there is.
     */
    public function testEditingTheLanguageRuleAlsoChangesTheSummaryFingerprint(): void
    {
        $before = $this->summariser->promptFingerprint();

        $this->override(PromptSlot::Language, 'Antworte immer auf Deutsch.');

        self::assertNotSame($before, $this->summariser->promptFingerprint());
    }

    /**
     * Clearing it puts the fingerprint back to what it was, exactly.
     *
     * Which is the other half of being honest: summaries written under the
     * shipped prompt, hidden while an override was in force, become visible
     * again the moment it is withdrawn — because they were in fact written by
     * the prompt that is now in force again. Nothing was deleted to achieve
     * that, and nothing had to be restored.
     */
    public function testClearingAnOverrideRestoresTheFingerprintItHadBefore(): void
    {
        $before = $this->summariser->promptFingerprint();

        $this->override(PromptSlot::Summary, 'You summarise email. Two sentences, no more.');
        $this->override(PromptSlot::Summary, '');

        self::assertSame($before, $this->summariser->promptFingerprint());
        self::assertNull($this->prompts->override(PromptSlot::Summary));
    }

    /**
     * Saving the same words again is not a change, and must not invalidate.
     *
     * An administrator who opens the card, reads a prompt and presses Save
     * without editing anything has not changed what a summary means. A key that
     * moved on every save — a timestamp, a revision counter — would throw the
     * whole cache away for a gesture that did nothing, and the person would pay
     * half a minute of GPU per thread to get the same paragraphs back.
     */
    public function testRetypingTheSameWordsIsNotAChange(): void
    {
        $this->override(PromptSlot::Summary, 'You summarise email. Two sentences, no more.');
        $after = $this->summariser->promptFingerprint();

        $this->override(PromptSlot::Summary, '  You summarise email. Two sentences, no more.  ');

        self::assertSame($after, $this->summariser->promptFingerprint());
    }

    /** A digest, in the shape the column holds and hash_equals compares. */
    public function testTheFingerprintIsASixtyFourCharacterHexDigest(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->summariser->promptFingerprint());
    }

    /**
     * The cap is enforced on the way in AND on the way out.
     *
     * Property hooks are skipped on hydration, so a row written by a
     * configuration restore or a psql session has never been past the setter —
     * and the cap is a budget the prompt has to fit inside on the wire, not a
     * rule about forms. Written straight through DBAL here, which is exactly
     * the path that bypasses the hook.
     */
    public function testAnOverstuffedPromptIsCutToTheCapEvenWhenItNeverPassedTheSetter(): void
    {
        $this->override(PromptSlot::Summary, 'seed');

        $this->connection->executeStatement(
            'UPDATE ai_settings SET prompt_summary = :text',
            ['text' => str_repeat('x', AiPrompts::MAX_LENGTH + 500)],
        );

        $this->em->clear();

        self::assertSame(
            AiPrompts::MAX_LENGTH,
            mb_strlen((string) $this->prompts->override(PromptSlot::Summary)),
        );
    }

    /** Categorisation is the one prompt with no language rule on the end. */
    public function testCategorisationIsSentAloneWhileTheOthersAreJoined(): void
    {
        self::assertSame(PromptSlot::Categorise->shipped(), $this->prompts->forCategorisation());

        self::assertStringEndsWith($this->prompts->language(), $this->prompts->forSummary());
        self::assertStringEndsWith($this->prompts->language(), $this->prompts->forTask(WritingTask::Proofread));
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
