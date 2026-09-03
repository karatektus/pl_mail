<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ai;

use App\Domain\Ai\EmbeddingPreset;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The presets, and the two things about them that can rot silently.
 *
 * Neither is about whether the numbers are good — that is a measurement, and it
 * is written into EmbeddingPreset's docblock next to the corpus it came from.
 * These are about the preset staying a coherent object as models are added.
 */
final class EmbeddingPresetTest extends KernelTestCase
{
    /**
     * Every preset's summary is translated in every language plMail ships.
     *
     * The key is DERIVED from the model name — `qwen3-embedding:0.6b` becomes
     * `qwen3-embedding_0_6b`, because a colon and a dot are both YAML structure
     * and neither survives being a key. That mangling is exactly the kind of
     * thing that is right when it is written and wrong the first time somebody
     * adds a model with a different punctuation, and the failure is a raw
     * translation key rendered in the admin panel where a sentence should be —
     * visible only to whoever opens that page in that language.
     *
     * Asserted against the translator rather than by reading the YAML, so this
     * also covers the catalogue being where the framework expects it.
     */
    public function testEverySummaryIsTranslatedEverywhere(): void
    {
        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (EmbeddingPreset::ordered() as $preset) {
            foreach (['en', 'de', 'en_PI'] as $locale) {
                $key  = $preset->summaryKey();
                $text = $translator->trans($key, [], 'messages', $locale);

                self::assertNotSame(
                    $key,
                    $text,
                    sprintf('%s has no %s summary; the admin panel would print the key', $preset->value, $locale),
                );
            }
        }
    }

    /**
     * A preset is only useful if both halves of it are present and in range.
     *
     * The threshold especially: it is bound straight into the search SQL, and
     * a preset shipping 0 would match the entire mailbox while one shipping
     * something above 1 would match nothing at all. Both read as a broken
     * search rather than a bad constant, which is why the admin form refuses
     * them too — this is the same guard on the values plMail ships itself.
     */
    public function testEveryPresetOffersAUsableThreshold(): void
    {
        foreach (EmbeddingPreset::ordered() as $preset) {
            $similarity = $preset->minSimilarity();

            self::assertGreaterThan(0.0, $similarity, $preset->value);
            self::assertLessThanOrEqual(1.0, $similarity, $preset->value);
        }

        // ordered() is what the panel renders, so a case missing from it is a
        // preset that exists and cannot be chosen.
        self::assertCount(count(EmbeddingPreset::cases()), EmbeddingPreset::ordered());
    }

    /**
     * An unknown model gets no preset, and specifically not the default's.
     *
     * Falling back to Qwen's numbers for somebody running something else would
     * be a threshold from the wrong scale, which is the whole failure this
     * class exists to end — and it would be applied silently, to a model plMail
     * has never measured.
     */
    public function testAnUnknownModelHasNoPreset(): void
    {
        self::assertNull(EmbeddingPreset::forModel('some-model-nobody-measured'));
        self::assertNull(EmbeddingPreset::forModel(null));
        self::assertSame(
            EmbeddingPreset::Qwen3Embedding06b,
            EmbeddingPreset::forModel('  qwen3-embedding:0.6b  '),
            'a name pasted with whitespace is the same model',
        );
    }
}
