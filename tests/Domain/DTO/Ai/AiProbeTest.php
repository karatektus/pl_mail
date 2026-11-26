<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Ai;

use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\AiWarmUp;
use App\Domain\DTO\Ai\OllamaModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * What a failed test of the model host hands to a translation.
 *
 * The reason is a key and its details are parameters, so that the sentence is
 * written in three languages by somebody who is not this class. The parameters
 * are only useful if the translation can find them, and it finds them by exact
 * key — percent signs included.
 */
final class AiProbeTest extends TestCase
{
    /**
     * Bare keys left the percent signs standing: "HTTP %status%" was shown to
     * an administrator as "HTTP %403%".
     */
    public function testAReasonsParametersReplaceTheWholePlaceholder(): void
    {
        $probe = AiProbe::unreachable('status', ['status' => 403]);

        self::assertSame(['%status%' => 403], $probe->placeholders());
        self::assertSame(
            'That host answered with HTTP 403.',
            new IdentityTranslator()->trans('That host answered with HTTP %status%.', $probe->placeholders()),
        );
    }

    /** The lookup by bare key is what every existing caller reads, and stays. */
    public function testTheParametersAreStillReadableByTheirOwnNames(): void
    {
        $probe = AiProbe::unreachable('unreachable', ['error' => 'connection refused']);

        self::assertSame('connection refused', $probe->reasonParams['error']);
    }

    /** The warm-up button's refusals go through the same translations. */
    public function testAWarmUpsParametersAreWrappedTheSameWay(): void
    {
        $warmUp = AiWarmUp::failed('model_missing', ['model' => 'qwen3', 'error' => 'not found']);

        self::assertSame(['%model%' => 'qwen3', '%error%' => 'not found'], $warmUp->placeholders());
    }

    /**
     * Gemini lists "models/gemini-3.8-flash" and answers to "gemini-3.8-flash".
     * The test called that model missing while every request used it (#743).
     */
    public function testAModelListedWithGooglesPrefixMatchesTheBareName(): void
    {
        $probe = AiProbe::reachable([
            new OllamaModel('models/gemini-3.8-flash'),
            new OllamaModel('models/gemini-embedding-2'),
        ]);

        self::assertTrue($probe->hasModel('gemini-3.8-flash'));
        self::assertTrue($probe->hasModel('gemini-embedding-2'));
        self::assertTrue($probe->hasModel('models/gemini-3.8-flash'), 'typed the way the list spells it');
    }

    /** The prefix is ignored, not the rest of the name: a neighbour is not a match. */
    public function testThePrefixDoesNotMakeANeighbourMatch(): void
    {
        $probe = AiProbe::reachable([new OllamaModel('models/gemini-3.8-flash-lite-tts')]);

        self::assertFalse($probe->hasModel('gemini-3.8-flash'));
        self::assertFalse($probe->hasModel('gemini-embedding-2'));
    }

    /** Ollama's default-tag tolerance is unchanged. */
    public function testOllamasDefaultTagStillMatches(): void
    {
        $probe = AiProbe::reachable([new OllamaModel('llama3.1:8b')]);

        self::assertTrue($probe->hasModel('llama3.1'));
        self::assertTrue($probe->hasModel('llama3.1:8b'));
        self::assertFalse($probe->hasModel('llama3.1:70b'));
    }
}
