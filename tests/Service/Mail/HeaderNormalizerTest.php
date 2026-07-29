<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\HeaderNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The bug this exists to prevent: the same header arriving under a different
 * key per provider, so a header-based mail rule matches on a Gmail account and
 * silently not on an IMAP one.
 */
final class HeaderNormalizerTest extends TestCase
{
    private HeaderNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new HeaderNormalizer();
    }

    public function testEveryProviderSpellingLandsOnOneKey(): void
    {
        $gmail = $this->normalizer->normalize(['List-Id' => '<a.example.test>']);
        $graph = $this->normalizer->normalize(['list-id' => '<a.example.test>']);
        $imap  = $this->normalizer->normalize(['list_id' => '<a.example.test>']);

        self::assertSame(['list-id'], array_keys($gmail));
        self::assertSame(array_keys($gmail), array_keys($graph));
        self::assertSame(array_keys($gmail), array_keys($imap));
    }

    public function testValuesAreLeftAlone(): void
    {
        $normalized = $this->normalizer->normalize([
            'Subject' => 'Keep_This_Exactly-As-It-Is',
        ]);

        self::assertSame('Keep_This_Exactly-As-It-Is', $normalized['subject']);
    }

    /**
     * A bag can carry both spellings — a Gmailified account whose messages
     * were touched by two paths. Neither value may be dropped.
     */
    public function testFoldedKeysMergeRatherThanOverwrite(): void
    {
        $normalized = $this->normalizer->normalize([
            'Received' => 'from a',
            'received' => 'from b',
        ]);

        self::assertSame(['received'], array_keys($normalized));
        self::assertSame(['from a', 'from b'], $normalized['received']);
    }

    public function testRepeatedHeaderListsSurvive(): void
    {
        $normalized = $this->normalizer->normalize([
            'Received' => ['from a', 'from b'],
        ]);

        self::assertSame(['from a', 'from b'], $normalized['received']);
    }

    public function testNormalisingTwiceChangesNothing(): void
    {
        $once  = $this->normalizer->normalize(['List-Id' => '<a>', 'X_Custom' => 'v']);
        $twice = $this->normalizer->normalize($once);

        self::assertSame($once, $twice, 'The backfill must be safe to re-run.');
    }

    public function testFirstReadsThroughEitherShape(): void
    {
        $bag = $this->normalizer->normalize([
            'List-Id'  => '<a.example.test>',
            'Received' => ['from a', 'from b'],
        ]);

        self::assertSame('<a.example.test>', $this->normalizer->first($bag, 'List-Id'));
        self::assertSame('from a', $this->normalizer->first($bag, 'received'));
        self::assertNull($this->normalizer->first($bag, 'X-Absent'));
        self::assertNull($this->normalizer->first(null, 'List-Id'));
    }

    public function testBlankNamesAreDropped(): void
    {
        self::assertSame([], $this->normalizer->normalize(['   ' => 'orphan']));
    }
}
