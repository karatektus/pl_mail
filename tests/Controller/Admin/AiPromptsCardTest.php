<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Enum\Ai\PromptSlot;
use App\Entity\Embeddable\AiPrompts;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Ai\PromptLibrary;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Admin → AI: the seven system prompts, and the round trip that edits one.
 *
 * WHAT IS ACTUALLY AT RISK HERE
 * ─────────────────────────────
 * Not the textareas. What this page can get wrong quietly is the FALLBACK: a
 * save that copies the shipped text into the database because the box was
 * pre-filled, or an empty box that stores '' instead of clearing the override.
 * Both look identical on screen and on the next render, and both are permanent
 * — the first pins the installation to today's wording so no later release can
 * improve it, and the second, for the language rule, is the exact regression
 * that rule was written to stop: a German mail answered in English and a German
 * draft translated while being proofread.
 *
 * So the assertions are about the COLUMN and about what PromptLibrary resolves,
 * not about the markup.
 *
 * AiSettingsCardTest is the sibling and covers the card above this one. Both
 * work inside one transaction that is rolled back: ai_settings is a singleton
 * and a leaked row would follow every later test in the process.
 */
final class AiPromptsCardTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private PromptLibrary $prompts;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->prompts    = $container->get(PromptLibrary::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);

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

    /**
     * Every slot gets a box, and every box starts EMPTY with the shipped text
     * as its placeholder.
     *
     * The empty box is the assertion that matters. A page that pre-filled the
     * defaults would look correct and would store a copy of them on the first
     * save, which is the failure this whole design is arranged against.
     */
    public function testThePageOffersEveryPromptWithTheShippedTextAsAPlaceholderAndAnEmptyBox(): void
    {
        $this->client->request('GET', '/admin/ai');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        foreach (PromptSlot::cases() as $slot) {
            self::assertStringContainsString(
                sprintf('name="prompt[%s]"', $slot->value),
                $html,
                sprintf('the %s prompt has no field on the page', $slot->value),
            );

            self::assertStringContainsString(
                htmlspecialchars(mb_substr($slot->shipped(), 0, 40), ENT_QUOTES),
                $html,
                sprintf('the shipped %s prompt is not shown anywhere', $slot->value),
            );
        }

        // Empty, all seven. `>{{ value }}</textarea>` with nothing between.
        self::assertSame(
            \count(PromptSlot::cases()),
            preg_match_all('/name="prompt\[[a-z]+\]"[^>]*>(?=<\/textarea>)/', $html),
            'a prompt box was pre-filled — saving would store a copy of the default',
        );
    }

    /** A typed prompt is stored, and is what the model would be sent. */
    public function testSavingAPromptStoresItAndPutsItInForce(): void
    {
        $this->submit(['summary' => 'You summarise email in exactly two sentences.']);

        self::assertResponseIsSuccessful();

        self::assertSame(
            'You summarise email in exactly two sentences.',
            $this->stored('summary'),
        );

        self::assertStringStartsWith(
            'You summarise email in exactly two sentences.',
            $this->prompts->forSummary(),
        );
    }

    /**
     * The other six are untouched by a save that only changed one.
     *
     * Every slot is written on every save, so this is the assertion that the
     * ones which came back empty were written as ABSENT rather than as ''.
     */
    public function testSavingOnePromptLeavesTheOthersOnTheShippedText(): void
    {
        $this->submit(['summary' => 'Two sentences, no more.']);

        foreach (PromptSlot::cases() as $slot) {
            if (PromptSlot::Summary === $slot) {
                continue;
            }

            self::assertNull(
                $this->stored($slot->value),
                sprintf('%s was written to the database by a save that did not touch it', $slot->value),
            );

            self::assertSame($slot->shipped(), $this->prompts->text($slot));
        }
    }

    /**
     * Clearing the box is how "put it back" is spelled, and it must clear the
     * COLUMN rather than store an empty prompt.
     *
     * Whitespace as well as '', because a textarea that has been selected and
     * deleted often keeps a newline behind.
     */
    public function testClearingAPromptRemovesTheOverrideRatherThanStoringNothing(): void
    {
        $this->submit(['language' => 'Antworte immer in der Sprache der Nachricht.']);

        self::assertNotNull($this->stored('language'));

        $this->submit(['language' => "  \n "]);

        self::assertNull(
            $this->stored('language'),
            'an emptied box stored an empty language rule instead of clearing the override',
        );

        self::assertSame(PromptSlot::Language->shipped(), $this->prompts->language());
    }

    /** Longer than the cap is cut, not refused: a paste loses its tail only. */
    public function testAnOverlongPromptIsTruncatedRatherThanRejected(): void
    {
        $this->submit(['formal' => str_repeat('a', 5000)]);

        self::assertResponseIsSuccessful();

        self::assertSame(
            AiPrompts::MAX_LENGTH,
            mb_strlen((string) $this->stored('formal')),
        );
    }

    /** No token, no write. */
    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $this->client->catchExceptions(false);

        $this->expectException(AccessDeniedException::class);

        $this->client->request('POST', '/admin/ai/prompts', ['prompt' => ['summary' => 'nope']]);
    }

    /**
     * @param array<string, string> $typed
     */
    private function submit(array $typed): void
    {
        $fields = [];

        foreach (PromptSlot::cases() as $slot) {
            $fields[$slot->value] = $typed[$slot->value] ?? '';
        }

        // Read off the rendered form rather than minted here: a token minted
        // outside a request has no session behind it, and reading the real one
        // also asserts the form actually carries it.
        $crawler = $this->client->request('GET', '/admin/ai');

        $token = (string) $crawler
            ->filter('form[action="/admin/ai/prompts"] input[name="_token"]')
            ->first()
            ->attr('value');

        $this->client->request('POST', '/admin/ai/prompts', [
            '_token' => $token,
            'prompt' => $fields,
        ]);

        $this->em->clear();
    }

    private function stored(string $slot): ?string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT prompt_%s FROM ai_settings', $slot));

        return false === $value || null === $value ? null : (string) $value;
    }
}
