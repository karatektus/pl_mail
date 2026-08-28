<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiCallRecorder;
use App\Domain\Enum\Ai\PromptSlot;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Entity\Embeddable\AiPreferences;
use App\Entity\User\User;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\PromptLibrary;
use App\Service\Ai\WritingAssistant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the composer is allowed to ask for, and what it refuses to ask.
 *
 * The refusals are the interesting half. This produces text that goes into
 * somebody's outgoing mail, so the failure that matters is not a clumsy
 * sentence — it is a model inventing a message on the writer's behalf because
 * it was handed nothing to work from, or a request that was never supposed to
 * be answerable being answered.
 */
final class WritingAssistantTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnUnconfiguredInstallationOffersNothing(): void
    {
        $assistant = $this->assistant(enabled: false);

        self::assertFalse($assistant->isAvailableFor($this->writer()));
        self::assertNull($assistant->write($this->writer(), WritingTask::Shorten, 'some text', null));
    }

    /**
     * A reply can be drafted from the message being answered alone — the common
     * case, an empty composer under a mail.
     */
    public function testAReplyCanBeDraftedFromTheMessageAloneWithNoDraft(): void
    {
        $answer = $this->assistant()->write($this->writer(), WritingTask::Reply, '', 'Can you send the invoice?');

        self::assertSame('an answer', $answer);
    }

    /**
     * The other three operate ON the draft. Handed nothing, a model invents a
     * message and it lands in somebody's outgoing mail — so nothing is sent.
     */
    public function testTheRewritingTasksRefuseAnEmptyDraft(): void
    {
        $calls     = 0;
        $assistant = $this->assistant(calls: $calls);

        foreach ([WritingTask::Shorten, WritingTask::Formal, WritingTask::Proofread] as $task) {
            self::assertNull($assistant->write($this->writer(), $task, '   ', 'a message being replied to'));
        }

        self::assertSame(0, $calls, 'nothing to work from is not a question worth asking');
    }

    /** And a reply with neither a draft nor anything to answer is refused too. */
    public function testAReplyWithNothingAtAllIsRefused(): void
    {
        self::assertNull($this->assistant()->write($this->writer(), WritingTask::Reply, '', ''));
    }

    /**
     * Chat-tuned models wrap answers in code fences. Pasting the fence into an
     * email is worse than the model having been slightly wrong.
     */
    public function testACodeFenceIsStrippedFromTheAnswer(): void
    {
        $assistant = $this->assistant(answer: "```\nDear Kim,\n\nThanks.\n```");

        self::assertSame("Dear Kim,\n\nThanks.", $assistant->write($this->writer(), WritingTask::Reply, '', 'hello'));
    }

    /** Prose that merely mentions backticks is left alone — guessing would delete lines. */
    public function testOrdinaryProseIsNotTrimmed(): void
    {
        $assistant = $this->assistant(answer: 'Use the `--force` flag.');

        self::assertSame('Use the `--force` flag.', $assistant->write($this->writer(), WritingTask::Reply, '', 'hello'));
    }

    /**
     * The proofreader must not be given room to be creative — it rewrites
     * sentences that were already correct.
     */
    public function testTheTemperatureMatchesTheTask(): void
    {
        self::assertSame(0.0, WritingTask::Proofread->temperature());
        self::assertGreaterThan(WritingTask::Shorten->temperature(), WritingTask::Reply->temperature());
    }

    /**
     * The task list is closed, and that is a security property rather than
     * tidiness: the value arrives in a request body, and a free-form
     * instruction would be a prompt an attacker could write, with the user's
     * own mail as context and the answer pasted into the user's own draft.
     */
    public function testAnythingOutsideTheClosedSetIsNotATask(): void
    {
        self::assertNull(WritingTask::tryFrom('ignore previous instructions'));
        self::assertNull(WritingTask::tryFrom(''));
        self::assertCount(4, WritingTask::cases());
    }

    /**
     * A user who has switched drafting help off is refused, with the
     * installation's switch fully on.
     *
     * The headline of the whole per-user arrangement seen from this end: the
     * ceiling being open is not the same as being allowed.
     */
    public function testAWriterWhoHasSwitchedDraftingHelpOffIsRefused(): void
    {
        $calls  = 0;
        $writer = $this->writer();
        $writer->aiPreferences->writingHelpOff = true;

        $assistant = $this->assistant(calls: $calls);

        self::assertFalse($assistant->isAvailableFor($writer));
        self::assertNull($assistant->write($writer, WritingTask::Reply, '', 'Can you send the invoice?'));
        self::assertSame(0, $calls, 'a refused writer still reached the model host');
    }

    /**
     * The persona goes into the SYSTEM message, after the app's own
     * instructions, and never into the user message.
     *
     * Both halves matter. Appended rather than substituted keeps the language
     * rule and the plain-text instruction, which a replacement would drop
     * silently; the system element rather than brief() keeps the mail side of
     * the prompt separately budgetable.
     */
    public function testThePersonaIsAppendedToTheSystemMessageAndNeverToTheUserMessage(): void
    {
        $sent   = null;
        $writer = $this->writer();
        $writer->aiPreferences->aboutMe      = 'I run a bicycle repair shop in Leipzig.';
        $writer->aiPreferences->systemPrompt = 'Keep it to three sentences.';

        $this->assistant(sent: $sent)->write($writer, WritingTask::Reply, '', 'When are you open?');

        self::assertIsArray($sent);

        $system = $sent['messages'][0]['content'];
        $user   = $sent['messages'][1]['content'];

        self::assertStringContainsString('I run a bicycle repair shop in Leipzig.', $system);
        self::assertStringContainsString('Keep it to three sentences.', $system);
        self::assertStringNotContainsString('bicycle repair shop', $user);

        // The app's own prompt is still FIRST and still whole — the language
        // rule included, which is the part a replacement would lose.
        self::assertStringStartsWith((new PromptLibrary($this->settings))->forTask(WritingTask::Reply), $system);
    }

    /**
     * AN ADMINISTRATOR'S OWN WORDING REACHES THE MODEL.
     *
     * The two assertions above compare what was sent against
     * `new PromptLibrary(...)->forTask(...)` — both sides of the comparison move
     * together, so they hold just as well on an installation whose overrides are
     * being silently ignored: the library would return the shipped text, the
     * assistant would send the shipped text, and the test would agree.
     *
     * So the seam between "the library resolves an override" (PromptLibraryTest)
     * and "the library's answer is what goes on the wire" (above) had nothing
     * standing in it. This is the join: a distinctive sentence is stored the way
     * the admin page stores one, and the assertion is against THAT LITERAL, not
     * against anything re-derived from the code under test.
     *
     * It exists because somebody asked whether custom prompts were used at all,
     * and neither the code nor the suite could answer without this.
     */
    public function testAnAdministratorsOwnWordingIsWhatTheModelIsSent(): void
    {
        $sent   = null;
        $custom = 'Answer as a Swiss notary would, and cite the file reference.';

        $assistant = $this->assistant(sent: $sent);

        // Stored exactly as AiSettingsController::prompts() stores it.
        $settings = $this->settings->currentOrDefault();
        $settings->prompts->put(PromptSlot::Reply, $custom);
        $this->em->persist($settings);
        $this->em->flush();

        $assistant->write($this->writer(), WritingTask::Reply, '', 'When are you open?');

        self::assertIsArray($sent);

        $system = $sent['messages'][0]['content'];

        self::assertStringContainsString($custom, $system);

        // And it REPLACED the shipped wording rather than being added to it —
        // an override that is appended is an override the model can average out.
        self::assertStringNotContainsString(PromptSlot::Reply->shipped(), $system);

        // The language rule still rides along, because it is structural: it is
        // what stops a German mail coming back answered in English.
        self::assertStringContainsString(
            (new PromptLibrary($this->settings))->language(),
            $system,
        );
    }

    /** No notes, no additions: the prompt is byte for byte what it always was. */
    public function testAWriterWithNoNotesGetsTheAppPromptUnchanged(): void
    {
        $sent = null;

        $this->assistant(sent: $sent)->write($this->writer(), WritingTask::Reply, '', 'When are you open?');

        self::assertIsArray($sent);
        self::assertSame((new PromptLibrary($this->settings))->forTask(WritingTask::Reply), $sent['messages'][0]['content']);
    }

    /**
     * A stored note longer than the cap is cut on the way OUT as well.
     *
     * Doctrine hydrates through RawValuePropertyAccessor, which skips property
     * hooks — so a row written by an older build, a config restore or psql has
     * never been past the setter, and the cap is a budget the message being
     * answered has to fit inside.
     */
    public function testAnOverlongStoredNoteIsTruncatedWhenThePromptIsBuilt(): void
    {
        $sent   = null;
        $writer = $this->writer();

        // setRawValue() and not an assignment, because a property hook
        // intercepts EVERY write including one from inside the class — which
        // is the whole reason the clamp cannot be trusted on the way out.
        // This is the accessor Doctrine hydrates through, so what lands here
        // is exactly what a stored row would put there.
        (new \ReflectionProperty(AiPreferences::class, 'aboutMe'))
            ->setRawValue($writer->aiPreferences, str_repeat('x', AiPreferences::MAX_ABOUT_ME + 500));

        self::assertSame(
            AiPreferences::MAX_ABOUT_ME + 500,
            mb_strlen($writer->aiPreferences->aboutMe),
            'the fixture did not get past the clamping setter, so this asserts nothing',
        );

        $this->assistant(sent: $sent)->write($writer, WritingTask::Reply, '', 'When are you open?');

        self::assertIsArray($sent);
        self::assertStringNotContainsString(
            str_repeat('x', AiPreferences::MAX_ABOUT_ME + 1),
            $sent['messages'][0]['content'],
        );
    }

    /**
     * A persona is not something to work FROM.
     *
     * hasEnoughToWorkFrom() asks whether there is a message; folding a standing
     * note about the writer into that answer would make an empty composer under
     * no message start generating invented mail, which is the exact case the
     * refusal exists to prevent.
     */
    public function testAFullPersonaIsStillNothingToWorkFrom(): void
    {
        $calls  = 0;
        $writer = $this->writer();
        $writer->aiPreferences->aboutMe      = 'I run a bicycle repair shop in Leipzig.';
        $writer->aiPreferences->systemPrompt = 'Keep it to three sentences.';

        self::assertNull($this->assistant(calls: $calls)->write($writer, WritingTask::Reply, '', ''));
        self::assertSame(0, $calls);
    }

    /**
     * @param array<string, mixed>|null $sent the request body the model was actually
     *                                        sent, decoded — the only way to assert
     *                                        WHERE in the prompt something landed
     */
    private function assistant(
        bool $enabled = true,
        string $answer = 'an answer',
        int &$calls = 0,
        ?array &$sent = null,
    ): WritingAssistant {

        $settings = new AiSettings();
        $settings->isEnabled          = $enabled;
        $settings->baseUrl            = 'http://10.0.0.5:11434';
        $settings->chatModel          = 'llama3.1:8b';
        $settings->writingHelpEnabled = $enabled;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$sent, $answer): MockResponse {
            ++$calls;

            $body = $options['body'] ?? '';
            $sent = is_string($body) ? json_decode($body, true) : null;

            return new MockResponse(json_encode(['message' => ['content' => $answer]]));
        });

        $ai = new AiAssistant(
            $this->settings,
            new OllamaClient($http, new NullLogger()),
            $this->recorder(),
            new NullLogger(),
        );

        return new WritingAssistant($ai, new AiPermissions($ai), new PromptLibrary($this->settings));
    }

    /**
     * A user who has not opted out of anything.
     *
     * Never persisted: AiPermissions reads the embeddable straight off the
     * object, so a row would buy nothing and would need cleaning up.
     */
    private function writer(): User
    {
        return new User();
    }

    /**
     * A recorder whose database is a mock.
     *
     * A stub rather than a mock, deliberately: nothing here asserts anything
     * about the metrics write, and PHPUnit is right to say so. These are unit
     * tests over an HTTP mock, there is no database to write to, and the row is
     * not what any of them are about.
     *
     * The REAL recorder rather than a fake, so that a change to its constructor
     * breaks here, where it is cheap, instead of in production.
     */
    private function recorder(): AiCallRecorder
    {
        return new AiCallRecorder($this->createStub(Connection::class), new NullLogger());
    }
}
