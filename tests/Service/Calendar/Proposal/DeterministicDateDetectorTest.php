<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Proposal;

use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Proposal\DeterministicDateDetector;
use App\Service\Calendar\Proposal\ProposalContext;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the parser reads, and what it refuses to read.
 *
 * Table-driven because the subject is a table: every accepted form is one row,
 * every deliberate refusal is another, and the interesting cases are the pairs
 * that look alike — `04.08.2026`, which is a date, against `04.08.26`, which is
 * three numbers nobody can safely order.
 *
 * The claim that matters most is the last group. Every relative form resolves
 * against the message's own date and never against the clock: "Saturday" in a
 * mail sent on a Friday in March 2025 has to still be that Saturday in March
 * 2025 when a backfill re-reads it. That bug is invisible when it ships —
 * everything looks right for as long as the mail is recent — and only appears
 * months later as a calendar full of dates that were never in anybody's mail.
 * The 2025 cases below fail against it by construction: no "now" produces them.
 *
 * A plain TestCase and no container, because there is nothing here but a
 * function. The detector takes no collaborators at all, which is deliberate —
 * it is the one part of this feature that has to be reproducible from its
 * arguments alone.
 */
final class DeterministicDateDetectorTest extends TestCase
{
    private DeterministicDateDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new DeterministicDateDetector();
    }

    /**
     * @param non-empty-string $anchor
     */
    #[DataProvider('statedDates')]
    #[DataProvider('relativeDates')]
    #[DataProvider('durations')]
    #[DataProvider('refusals')]
    public function testItReadsWhatItCanAndRefusesTheRest(
        string  $text,
        string  $language,
        string  $anchor,
        ?string $expectedStart,
        ?string $expectedEnd = null,
        string  $zone = 'UTC',
    ): void {
        $found = $this->detector->detect($this->context($text, $language, $anchor, $zone));

        if (null === $expectedStart) {
            self::assertNull($found, 'this form is too ambiguous to act on');

            return;
        }

        self::assertNotNull($found, 'this form is one the parser claims to read');
        self::assertSame($expectedStart, $found->startsAt->format('Y-m-d H:i'));

        if (null !== $expectedEnd) {
            self::assertSame($expectedEnd, $found->endsAt->format('Y-m-d H:i'));
        }
    }

    /**
     * Dates the mail states outright.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4?: ?string, 5?: string}>
     */
    public static function statedDates(): array
    {
        return [
            'German numeric with Uhr' => [
                'Termin wie vereinbart: 04.08.2026 um 14 Uhr', 'de', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            'German numeric with a clock time' => [
                'Wir sehen uns am 04.08.2026 14:00 im Büro.', 'de', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            'German month spelled out' => [
                '4. August 2026, 14:00 Uhr', 'de', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            'German month spelled out without a year' => [
                'Am 4. August um 14 Uhr, ja?', 'de', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            'German dotted clock time insists on Uhr' => [
                'Beginn 04.08.2026 um 14.30 Uhr', 'de', '2026-07-31 09:00', '2026-08-04 14:30',
            ],
            'English month first' => [
                'Shall we say August 4, 2026 at 2pm?', 'en', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            'English day first' => [
                'Pencilled in for 4th August 2026 at 2 p.m.', 'en', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            'ISO, which no locale reads two ways' => [
                'Kickoff 2026-08-04 14:00 sharp', 'en', '2026-07-31 09:00', '2026-08-04 14:00',
            ],
            // The same eight characters, two dates. This pair is the whole
            // reason the user's locale is carried into the parse.
            'slashed, read by a German reader' => [
                'Termin 04/08/2026 um 14 Uhr', 'de', '2026-01-01 09:00', '2026-08-04 14:00',
            ],
            'slashed, read by an English reader' => [
                'Booked for 04/08/2026 at 2pm', 'en', '2026-01-01 09:00', '2026-04-08 14:00',
            ],
            'midnight and noon are not the same hour' => [
                'See you on 2026-08-04 at 12am', 'en', '2026-07-31 09:00', '2026-08-04 00:00',
            ],
            // The wall clock belongs to the reader: "14 Uhr" is two in the
            // afternoon in Berlin, which is noon in the column.
            'the stated hour is local to the user' => [
                '04.08.2026 um 14 Uhr', 'de', '2026-07-31 09:00', '2026-08-04 12:00', null, 'Europe/Berlin',
            ],
        ];
    }

    /**
     * Dates that only mean something relative to when the mail was sent.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4?: ?string, 5?: string}>
     */
    public static function relativeDates(): array
    {
        return [
            'a weekday means the next one' => [
                'hey lets meet up on saturday at 3pm', 'en', '2026-07-31 09:00', '2026-08-01 15:00',
            ],
            'a German weekday, the same way' => [
                'Samstag um 15 Uhr bei mir?', 'de', '2026-07-31 09:00', '2026-08-01 15:00',
            ],
            // Written on the Saturday itself, after the hour named. Nobody
            // arranges a meeting for three hours ago.
            'a weekday already gone by means the one coming' => [
                'Samstag um 15 Uhr?', 'de', '2026-08-01 18:00', '2026-08-08 15:00',
            ],
            'tomorrow' => [
                'can you do tomorrow at 9?', 'en', '2026-07-31 09:00', '2026-08-01 09:00',
            ],
            'morgen' => [
                'Geht morgen um 9 Uhr?', 'de', '2026-07-31 09:00', '2026-08-01 09:00',
            ],
            // "morgen" sits inside "übermorgen" as far as a word boundary is
            // concerned, so the longer word has to be tried first or every
            // day-after-tomorrow lands twenty-four hours early.
            'übermorgen is not tomorrow' => [
                'Übermorgen um 9 Uhr?', 'de', '2026-07-31 09:00', '2026-08-02 09:00',
            ],
            'next Tuesday' => [
                'next Tuesday 10:30 works for me', 'en', '2026-07-31 09:00', '2026-08-04 10:30',
            ],
            'nächsten Dienstag' => [
                'Nächsten Dienstag 10:30?', 'de', '2026-07-31 09:00', '2026-08-04 10:30',
            ],
            // Anchored eighteen months before this test was written, so no
            // reading of the clock produces it.
            'the anchor is the message, not today' => [
                'lets meet up on saturday at 3pm', 'en', '2025-03-07 09:00', '2025-03-08 15:00',
            ],
            'and again for a stated weekday in German' => [
                'Samstag um 15 Uhr', 'de', '2025-03-07 09:00', '2025-03-08 15:00',
            ],
        ];
    }

    /**
     * How long the mail said it would take, where it said.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4?: ?string, 5?: string}>
     */
    public static function durations(): array
    {
        return [
            // The worked example: the appointment is on one line and its
            // length on another, which is why a duration is looked for over
            // the whole body rather than in the sentence that names the time.
            'German hours on a line of their own' => [
                "Termin wie vereinbart: 04.08.2026 um 14 Uhr\nZeitrahmen: 2 Stunden",
                'de', '2026-07-31 09:00', '2026-08-04 14:00', '2026-08-04 16:00',
            ],
            'English hours' => [
                'Kickoff 2026-08-04 14:00, should take 2 hours',
                'en', '2026-07-31 09:00', '2026-08-04 14:00', '2026-08-04 16:00',
            ],
            'German minutes' => [
                "04.08.2026 um 14 Uhr\nDauer: 90 Minuten",
                'de', '2026-07-31 09:00', '2026-08-04 14:00', '2026-08-04 15:30',
            ],
            'an hour, spelled' => [
                'Kickoff 2026-08-04 14:00, an hour at most',
                'en', '2026-07-31 09:00', '2026-08-04 14:00', '2026-08-04 15:00',
            ],
            // An unstated length is an hour, not zero: a zero-length event is
            // invisible in every calendar view.
            'no stated length is one hour' => [
                'hey lets meet up on saturday at 3pm',
                'en', '2026-07-31 09:00', '2026-08-01 15:00', '2026-08-01 16:00',
            ],
            // "48 Stunden" is a deadline, not the length of a meeting.
            'a length nobody could sit through is ignored' => [
                "04.08.2026 um 14 Uhr\nAntwort innerhalb von 48 Stunden",
                'de', '2026-07-31 09:00', '2026-08-04 14:00', '2026-08-04 15:00',
            ],
        ];
    }

    /**
     * The forms this deliberately will not read. Each of them is a date to a
     * human and a coin flip to a parser.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4?: ?string, 5?: string}>
     */
    public static function refusals(): array
    {
        return [
            // Twenty-six is a day in some hands and a year in others, and the
            // century would be a further guess on top of that one.
            'a two-digit year' => [
                'Termin: 04.08.26 um 14 Uhr', 'de', '2026-07-31 09:00', null,
            ],
            'a day and a month with no year' => [
                'Termin: 04.08. um 14 Uhr', 'de', '2026-07-31 09:00', null,
            ],
            'a time with no day' => [
                "let's meet at 3pm", 'en', '2026-07-31 09:00', null,
            ],
            'a date with no time' => [
                'Der Vertrag läuft bis 04.08.2026.', 'de', '2026-07-31 09:00', null,
            ],
            // Two facts, two sentences, no appointment.
            'a date and a time that are not about each other' => [
                "Der Vertrag läuft bis 31.12.2026.\nUnsere Bürozeiten sind 9:00 bis 17:00.",
                'de', '2026-07-31 09:00', null,
            ],
            // The same two facts inside one grammatical sentence. Being in the
            // same sentence is not enough — a paragraph naming a deadline at
            // one end and an opening hour at the other is still two facts.
            'a date and a time too far apart to be one appointment' => [
                'Der Vertrag vom 31.12.2026 regelt Kündigung, Haftung und Vergütung, '
                . 'und unsere Bürozeiten bleiben unverändert 9:00 bis 17:00 Uhr',
                'de', '2026-07-31 09:00', null,
            ],
            // The locale says the first number is the month; thirteen is not a
            // month; so the answer is no, rather than the other reading.
            'a slashed date the reader\'s own locale cannot read' => [
                'Booked for 13/25/2026 at 2pm', 'en', '2026-07-31 09:00', null,
            ],
            'a bare number no preposition introduces' => [
                'Saturday, room 3, seats 12', 'en', '2026-07-31 09:00', null,
            ],
            // Found by running the parser over a real mailbox: almost every
            // date it read was a reply's own furniture, describing when the
            // message underneath was sent.
            'an English quote attribution' => [
                "Sounds good.\nOn Thu, Aug 6, 2026 at 12:56 PM Paul <paul@example.org> wrote:",
                'en', '2026-08-08 09:00', null,
            ],
            'a German quote attribution' => [
                "Passt.\nAm 06.08.2026 um 12:56 schrieb Paul:",
                'de', '2026-08-08 09:00', null,
            ],
            'a forwarded header block' => [
                "FYI\n---------- Forwarded message ----------\nSent: Friday, August 7, 2026 3:58 PM",
                'en', '2026-08-08 09:00', null,
            ],
            'quoted text' => [
                "no idea\n> shall we say 04.09.2026 um 14 Uhr?",
                'de', '2026-07-31 09:00', null,
            ],
            'prose with nothing date-shaped in it' => [
                'Thanks very much, that all sounds good.', 'en', '2026-07-31 09:00', null,
            ],
        ];
    }

    private function context(string $text, string $language, string $anchor, string $zone): ProposalContext
    {
        return new ProposalContext(
            message:  new Message(),
            usr:      new User(),
            anchor:   new DateTimeImmutable($anchor, new DateTimeZone('UTC')),
            zone:     new DateTimeZone($zone),
            language: $language,
            text:     $text,
        );
    }
}
