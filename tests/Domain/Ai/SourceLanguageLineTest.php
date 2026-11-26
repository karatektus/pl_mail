<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ai;

use App\Domain\Ai\SourceLanguageLine;
use PHPUnit\Framework\TestCase;

/**
 * The line a translation starts with, naming the mail's language (#713).
 *
 * What is at risk: the line reaching the reader as part of the translation,
 * a translation losing its first words to a line that was not there, and a
 * wrong language stored, which the page would then name.
 */
final class SourceLanguageLineTest extends TestCase
{
    public function testTheLineIsTakenOffAndItsLanguageRead(): void
    {
        $line = SourceLanguageLine::read("Language: he\n\nПришлите счёт до четверга.", false);

        self::assertNotNull($line);
        self::assertSame('he', $line->language);
        self::assertSame('Пришлите счёт до четверга.', $line->rest);
    }

    /** A model's decoration around the line is still the line. */
    public function testBoldUpperCaseAndAFullStopAreStillTheLine(): void
    {
        foreach (["**Language:** HE.\nText", "  language : he\r\nText", "`Language: he`\nText"] as $answer) {
            $line = SourceLanguageLine::read($answer, false);

            self::assertNotNull($line, $answer);
            self::assertSame('he', $line->language, $answer);
            self::assertSame('Text', $line->rest, $answer);
        }
    }

    /** A region after the language is still the language. */
    public function testARegionIsTakenOffTheCode(): void
    {
        foreach (["Language: pt-BR\nText", "Language: zh_CN\nText"] as $answer) {
            $line = SourceLanguageLine::read($answer, false);

            self::assertNotNull($line, $answer);
            self::assertSame(substr($answer, 10, 2), $line->language, $answer);
            self::assertSame('Text', $line->rest, $answer);
        }
    }

    /**
     * A model that fences its whole answer writes the line inside the fence.
     * The fence stays for MessageTranslator::tidied() to take off; the line
     * must not be left at the top of the translation once it has.
     */
    public function testTheLineInsideAFencedAnswerIsTakenOff(): void
    {
        self::assertNull(SourceLanguageLine::read("```\n", false));

        $line = SourceLanguageLine::read("```text\nLanguage: he\n\nПришлите счёт.\n```", false);

        self::assertNotNull($line);
        self::assertSame('he', $line->language);
        self::assertSame("```text\nПришлите счёт.\n```", $line->rest);
    }

    /** Held back while it may still be the line: nothing of it may be shown yet. */
    public function testAnUnfinishedFirstLineIsWaitedFor(): void
    {
        self::assertNull(SourceLanguageLine::read('Langu', false));
        self::assertNull(SourceLanguageLine::read('Language: h', false));
        self::assertNull(SourceLanguageLine::read("\n", false));
    }

    /** But not for longer than the line could be: a long first line is translation. */
    public function testALongFirstLineIsNotWaitedForToEnd(): void
    {
        $start = str_repeat('Пришлите ', 8);
        $line  = SourceLanguageLine::read($start, false);

        self::assertNotNull($line);
        self::assertNull($line->language);
        self::assertSame($start, $line->rest);
    }

    /** An answer that starts with the translation keeps every word of it. */
    public function testAnAnswerWithoutTheLineIsAllTranslation(): void
    {
        $line = SourceLanguageLine::read("Здравствуйте, Дмитрий,\nпришлите счёт.", false);

        self::assertNotNull($line);
        self::assertNull($line->language);
        self::assertSame("Здравствуйте, Дмитрий,\nпришлите счёт.", $line->rest);
    }

    /** A short answer with no line break, read once it is finished. */
    public function testAShortFinishedAnswerIsReadAsItIs(): void
    {
        $line = SourceLanguageLine::read('Danke.', true);

        self::assertNotNull($line);
        self::assertNull($line->language);
        self::assertSame('Danke.', $line->rest);
    }

    /**
     * The line, but not a language Intl knows by two letters: taken off,
     * because it is not the sender's words, and no language is named, because
     * a wrong one would be shown on the page.
     */
    public function testALineWithoutAKnownCodeIsTakenOffAndNamesNothing(): void
    {
        foreach (["Language: Hebrew\nText", "Language: heb\nText", "Language: qq\nText", "Language:\nText"] as $answer) {
            $line = SourceLanguageLine::read($answer, false);

            self::assertNotNull($line, $answer);
            self::assertNull($line->language, $answer);
            self::assertSame('Text', $line->rest, $answer);
        }
    }
}
