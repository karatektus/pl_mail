<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use PHPUnit\Framework\TestCase;

/**
 * A message id that is all digits is still a string.
 *
 * PHP converts a decimal-integer-like ARRAY KEY to an int on the way in, so an
 * id-keyed map hands back ints from array_keys() for exactly those ids that
 * happen to contain no letters. Gmail ids are hex, so most contain a letter and
 * most of the time nothing goes wrong — which is what made this wait.
 *
 * When it did bite, it bit hard. A relabelled id came back as an int, rode the
 * queue as one, and killed the batch at `urlencode(): Argument #1 ($string)
 * must be of type string, int given` — five retries, then the whole batch to
 * the failure transport. Every relabelled message in it, read state included,
 * was never re-read.
 *
 * The deletion path had the same shape and a quieter symptom: an int compared
 * against stored string ids matches nothing, so the message is not erased and
 * no error is raised at all.
 *
 * This pins the property rather than the call sites, because the trap is the
 * language's and the next id-keyed map will have it too.
 */
final class GmailIdsSurviveArrayKeysTest extends TestCase
{
    public function testAnAllDigitIdBecomesAnIntWhenUsedAsAnArrayKey(): void
    {
        $map = [];
        $map['1992288000000000'] = true;
        $map['18f2a1b3c4d5e6f7'] = true;

        $keys = array_keys($map);

        // The trap itself, asserted so nobody has to take it on trust.
        self::assertIsInt($keys[0], 'PHP no longer coerces numeric array keys — this test can go');
        self::assertIsString($keys[1]);
    }

    /**
     * And the shape the syncers use puts it back.
     *
     * `array_map(strval(...), array_keys($map))` is what both GmailApiSyncer
     * and GraphApiSyncer now do at every id-keyed boundary.
     */
    public function testCastingOnTheWayOutRestoresTheId(): void
    {
        $map = ['1992288000000000' => true, '18f2a1b3c4d5e6f7' => true];

        $ids = array_map(strval(...), array_keys($map));

        self::assertSame(['1992288000000000', '18f2a1b3c4d5e6f7'], $ids);

        foreach ($ids as $id) {
            // The call that actually threw.
            self::assertIsString(urlencode($id));
        }
    }
}
