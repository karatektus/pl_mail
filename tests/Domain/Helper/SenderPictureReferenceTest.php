<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\SenderPictureReference;
use PHPUnit\Framework\TestCase;

/**
 * A sender picture URL names one file, and only that file (#639).
 *
 * The route caches its answer as immutable for a year on the strength of
 * that, so the claim worth pinning is the refusal: a reference minted for an
 * older picture must not name the newer one that replaced it.
 */
final class SenderPictureReferenceTest extends TestCase
{
    public function testAReferenceRoundTripsAndNamesItsOwnFile(): void
    {
        $value  = SenderPictureReference::for(SenderPictureReference::CONTACT, 345, 'contacts/7/abc.jpg');
        $parsed = SenderPictureReference::parse($value);

        self::assertNotNull($parsed);
        self::assertSame(SenderPictureReference::CONTACT, $parsed->type);
        self::assertSame(345, $parsed->id);
        self::assertTrue($parsed->names('contacts/7/abc.jpg'));
    }

    public function testAReferenceToAReplacedPictureNamesNothingRatherThanTheNewOne(): void
    {
        $old = SenderPictureReference::parse(SenderPictureReference::for(SenderPictureReference::USER, 12, 'old.png'));

        self::assertNotNull($old);
        self::assertFalse($old->names('new.png'));
    }

    public function testAnythingElseIsNotAReference(): void
    {
        foreach (['', 'x12-0123456789ab', 'u0-0123456789ab', 'u12-0123', 'u12-0123456789ab/../x', 's12-0123456789AB'] as $value) {
            self::assertNull(SenderPictureReference::parse($value), $value);
        }
    }
}
