<?php

declare(strict_types=1);

namespace App\Tests\Documentation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Everything plMail says in German duzt.
 *
 * That is a decision about what the product sounds like, and it held everywhere
 * except a handful of strings — "Was jede **Ihrer** Adressen … tut", "Nur für
 * **Sie** sichtbar", "sobald **Sie** seine Nachricht gelesen haben". Reported
 * from a crawl of the running instance, which noticed it in the Aliases panel
 * and assumed that panel was the exception. It was not; it was where the reader
 * happened to look.
 *
 * ## How this can be tested at all
 *
 * German capitalises the polite second person — Sie, Ihr, Ihre, Ihnen — and
 * also the first word of every sentence. Third-person "sie/ihre" (it, they,
 * their) is lowercase mid-sentence. So a capitalised form in the MIDDLE of a
 * sentence can only be formal address, while the same word at the start of one
 * is ambiguous and is left alone.
 *
 * That is why this checks position rather than presence. A naive search for
 * "Sie" flags a dozen perfectly good sentences — "Sie wird verschlüsselt
 * gespeichert" about a file, "Sie verschwinden aus allen Konten" about labels —
 * and a test that cries wolf gets an exception list, and then the exception
 * list gets the next real one.
 *
 * The cost is that a sentence STARTING with formal address is not caught. Two
 * such existed and were fixed by hand; the rule catches the majority and never
 * lies about the rest, which is the better trade than an allow-list that rots.
 */
final class GermanIsInformalTest extends TestCase
{
    /**
     * Sie, Ihr and its declensions. `Ihres`/`Ihrem` are included even though
     * nothing uses them today — the point is to catch the next one.
     */
    private const string POLITE = '(Sie|Ihnen|Ihre[rnms]?|Ihr)';

    public function testNoGermanStringAddressesTheReaderFormally(): void
    {
        $offenders = [];

        foreach ($this->strings() as $key => $value) {
            foreach ($this->midSentenceHits($value) as $hit) {
                $offenders[] = sprintf('%s — "%s" in: %s', $key, $hit, $value);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "German is informal everywhere in plMail — these address the reader as Sie:\n"
            . implode("\n", $offenders),
        );
    }

    /**
     * The rule itself is worth a test, because a rule this one relies on being
     * exactly right is one that can be broken by a plausible-looking tweak.
     */
    public function testTheRuleSeparatesPolitenessFromCapitalisation(): void
    {
        // Formal address, mid-sentence: caught.
        self::assertNotSame([], $this->midSentenceHits('Nur für Sie sichtbar.'));
        self::assertNotSame([], $this->midSentenceHits('Was jede Ihrer Adressen tut.'));

        // Third person, mid-sentence and lowercase: not formal, not caught.
        self::assertSame([], $this->midSentenceHits('Die Datei wird gespeichert, sie bleibt hier.'));

        // Third person at the start of a sentence, capitalised because German
        // capitalises sentences: ambiguous, deliberately not caught.
        self::assertSame([], $this->midSentenceHits('Die Datei ist da. Sie wird verschlüsselt.'));
        self::assertSame([], $this->midSentenceHits('Sie verschwinden aus allen Konten.'));
    }

    /** @return list<string> */
    private function midSentenceHits(string $value): array
    {
        $hits = [];

        // Split on sentence ends, keeping it simple: what matters is that the
        // first word after one is exempt.
        foreach (preg_split('/(?<=[.!?:])\s+/u', $value) ?: [] as $sentence) {
            // Drop the first word — that is the position politeness cannot be
            // distinguished from capitalisation.
            $rest = (string) preg_replace('/^\S+\s*/u', '', $sentence);

            if (1 === preg_match('/\b' . self::POLITE . '\b/u', $rest, $match)) {
                $hits[] = $match[1];
            }
        }

        return $hits;
    }

    /**
     * @return array<string, string> every leaf of every German catalogue
     */
    private function strings(): array
    {
        $flat = [];

        $walk = static function (array $node, string $path) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $here = '' === $path ? (string) $key : $path . '.' . $key;

                if (is_array($value)) {
                    $walk($value, $here);

                    continue;
                }

                $flat[$here] = (string) $value;
            }
        };

        // Every `*.de.yaml`, not just `messages`. The register is a decision
        // about what the product sounds like, and the product says things from
        // three catalogues: `messages` on screen, `validators` under a field
        // the reader has just got wrong, and `security` on the login form. The
        // last two are exactly where a translator reaches for the formal voice,
        // because both are the software telling somebody they have made a
        // mistake — and both were outside this test until a sentence in
        // `security` needed writing.
        //
        // Globbed rather than listed, so a catalogue added next release is
        // covered without anybody remembering this file.
        $catalogues = glob(dirname(__DIR__, 2) . '/translations/*.de.yaml') ?: [];

        self::assertNotSame([], $catalogues, 'no German catalogues found — the glob is looking in the wrong place');

        foreach ($catalogues as $catalogue) {
            $walk((array) Yaml::parseFile($catalogue), basename($catalogue, '.de.yaml'));
        }

        return $flat;
    }
}
