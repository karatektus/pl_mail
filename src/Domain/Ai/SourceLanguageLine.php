<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Symfony\Component\Intl\Languages;

/**
 * The line a translation starts with, "Language: he", read off the start of
 * the answer (#713). See PromptRules::TRANSLATION_SOURCE_LINE for why the
 * translating call names the language at all.
 *
 * Read while the answer streams, so the line is held back until it can be
 * told apart from the translation and never reaches the page or the store.
 *
 * STRICT ABOUT THE LANGUAGE, NOT ABOUT THE DECORATION. A model may bold the
 * line, end it with a full stop, add a region ("pt-BR") or fence the whole
 * answer as code; it is still the line. What it names must be a two-letter
 * ISO 639-1 code that Intl knows, because the code is stored and compared with
 * the reader's language. A line that says "Language:" and then something else
 * is still taken off the translation, with no language: it is not the sender's
 * words. An answer that does not start with the line is all translation, with
 * no language either. Never a guess: the page names no language rather than a
 * wrong one.
 */
final readonly class SourceLanguageLine
{
    /**
     * How much of an answer is held back while waiting for the end of its
     * first line. The line is about fifteen characters; past this the answer
     * did not start with it, and the reader should not wait for more.
     */
    public const int LONGEST = 64;

    private const string PATTERN = '/^[\s*_`#>]*language[\s*_`]*:[\s*_`]*([a-z]{2})(?:[-_][a-z0-9]{2,8})*[\s*_`.]*$/iu';
    private const string LABEL   = '/^[\s*_`#>]*language[\s*_`]*:/iu';

    /**
     * The opening of a fence around the whole answer. MessageTranslator::tidied()
     * takes the fence off the translation; the line inside it is taken off here,
     * or it would be left at the top of the stored text.
     */
    private const string FENCE = '/^```[a-z]*[ \t]*\r?\n/i';

    private function __construct(
        /** The two-letter code, lower case; null when the answer named none that Intl knows. */
        public ?string $language,
        /** The answer without the line: the translation as far as it has arrived. */
        public string $rest,
    ) {
    }

    /**
     * What the start of an answer says, once that can be told: null while its
     * first line is still being written, and $finished false.
     */
    public static function read(string $start, bool $finished): ?self
    {
        $trimmed = ltrim($start);
        $fence   = '';

        if (1 === preg_match(self::FENCE, $trimmed, $opening)) {
            $fence   = $opening[0];
            $trimmed = ltrim(substr($trimmed, strlen($fence)));
        }

        $break = strpos($trimmed, "\n");

        if (false === $break && false === $finished && mb_strlen($trimmed) < self::LONGEST) {
            return null;
        }

        $line = false === $break ? $trimmed : substr($trimmed, 0, $break);

        if (1 !== preg_match(self::LABEL, $line)) {
            return new self(null, $start);
        }

        $rest = $fence . (false === $break ? '' : ltrim(substr($trimmed, $break + 1), "\r\n"));

        if (1 !== preg_match(self::PATTERN, $line, $matches)) {
            return new self(null, $rest);
        }

        $code = strtolower($matches[1]);

        return new self(true === Languages::exists($code) ? $code : null, $rest);
    }
}
