<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\TwentyExtension;
use PHPUnit\Framework\TestCase;

/**
 * The link from an address to its person in Twenty, and its absence when no Twenty is configured.
 */
final class TwentyExtensionTest extends TestCase
{
    public function testTheLinkFiltersTwentysPeopleToTheAddress(): void
    {
        self::assertSame(
            'https://crm.example.com/objects/people?filter%5Bemails%5D%5BCONTAINS%5D=dana%2Breply%40example.com',
            new TwentyExtension('https://crm.example.com/')->personUrl(' dana+reply@example.com '),
        );
    }

    public function testNoTwentyMeansNoLink(): void
    {
        self::assertNull(new TwentyExtension(null)->personUrl('dana@example.com'));
        self::assertNull(new TwentyExtension('')->personUrl('dana@example.com'));
    }

    public function testNoAddressMeansNoLink(): void
    {
        self::assertNull(new TwentyExtension('https://crm.example.com')->personUrl(''));
    }
}
