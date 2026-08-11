<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\RecipientHeaderHelper;
use PHPUnit\Framework\TestCase;

/**
 * Reading recipients back out of the stored header bag.
 *
 * Two spellings have to work, because the ingest paths disagree: Gmail and
 * Graph keep the header name as it arrived (`To`, `Reply-To`), webklex
 * lowercases it and turns hyphens into underscores (`to`, `reply_to`). Every
 * lookup that knew only the hyphen form silently found nothing on IMAP mail —
 * which is why the details panel never showed reply-to, mailed-by or signed-by
 * for any of it.
 */
final class RecipientHeaderHelperTest extends TestCase
{
    public function testTheWebklexSpellingIsUnderstood(): void
    {
        $addresses = RecipientHeaderHelper::addresses(
            ['to' => 'Alice <alice@example.com>, bob@example.com'],
            'to',
        );

        self::assertSame(
            [
                ['name' => 'Alice', 'address' => 'alice@example.com'],
                ['name' => '', 'address' => 'bob@example.com'],
            ],
            $addresses,
        );
    }

    public function testTheArrivedAsSpellingIsUnderstood(): void
    {
        self::assertSame(
            [['name' => 'Alice', 'address' => 'alice@example.com']],
            RecipientHeaderHelper::addresses(['To' => 'Alice <alice@example.com>'], 'to'),
        );
    }

    /** A multi-value header is stored as a list and is still one address list. */
    public function testAMultiValueHeaderIsFlattened(): void
    {
        self::assertSame(
            [
                ['name' => 'Alice', 'address' => 'alice@example.com'],
                ['name' => 'Bob', 'address' => 'bob@example.com'],
            ],
            RecipientHeaderHelper::addresses(
                ['to' => ['Alice <alice@example.com>', 'Bob <bob@example.com>']],
                'to',
            ),
        );
    }

    /** A comma inside a quoted display name is not a separator. */
    public function testAQuotedNameIsNotSplitOnItsComma(): void
    {
        self::assertSame(
            [['name' => 'Doe, John', 'address' => 'john@example.com']],
            RecipientHeaderHelper::addresses(
                ['to' => '"Doe, John" <john@example.com>'],
                'to',
            ),
        );
    }

    public function testAGroupWithNoMembersYieldsNobody(): void
    {
        self::assertSame(
            [],
            RecipientHeaderHelper::addresses(['to' => 'undisclosed-recipients:;'], 'to'),
        );
    }

    public function testAnAbsentHeaderYieldsNobody(): void
    {
        self::assertSame([], RecipientHeaderHelper::addresses(['From' => 'x@y.test'], 'to'));
        self::assertSame([], RecipientHeaderHelper::addresses([], 'to'));
    }

    public function testCanonicalisationFoldsBothSpellingsOntoOne(): void
    {
        $canonical = RecipientHeaderHelper::canonicalise([
            'Reply-To'       => 'a@example.com',
            'dkim_signature' => 'v=1; d=example.com;',
            'Received'       => ['one', 'two'],
        ]);

        self::assertSame('a@example.com', $canonical['reply-to']);
        self::assertSame('v=1; d=example.com;', $canonical['dkim-signature']);
        self::assertSame("one\ntwo", $canonical['received']);
    }
}
