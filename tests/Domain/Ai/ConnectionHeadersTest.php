<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ai;

use App\Domain\Ai\ConnectionHeaders;
use App\Domain\DTO\Ai\AiConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What may be typed as an extra header, and what is done with it.
 *
 * A header is the one place on the AI page where a mistyped character becomes
 * part of every request plMail sends to somebody else's server. So the reading
 * is strict where somebody can still fix it, and forgiving where nobody can.
 */
final class ConnectionHeadersTest extends TestCase
{
    /** The way a provider's documentation shows them, pasted as they are. */
    public function testHeadersAreReadOneToALine(): void
    {
        self::assertSame(
            ['CF-Access-Client-Id' => 'abc.access', 'CF-Access-Client-Secret' => 'a:b:c', 'X-Tenant' => 'acme'],
            ConnectionHeaders::parse("CF-Access-Client-Id: abc.access\r\n\r\nCF-Access-Client-Secret:a:b:c\n  X-Tenant :  acme  \n"),
            'blank lines and padding are ignored, and only the first colon separates',
        );
    }

    /** Basic auth in front of an Ollama is the commonest use there is. */
    public function testAnAuthorizationHeaderMayBeTyped(): void
    {
        self::assertSame(['Authorization' => 'Basic dXNlcjpwYXNz'], ConnectionHeaders::parse('Authorization: Basic dXNlcjpwYXNz'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'no colon'                 => ['X-Tenant acme', 'admin.ai.headers.malformed'];
        yield 'no name'                  => [': acme', 'admin.ai.headers.name'];
        yield 'a space in the name'      => ['X Tenant: acme', 'admin.ai.headers.name'];
        yield 'no value'                 => ['X-Tenant:', 'admin.ai.headers.value'];
        yield 'a control character'      => ["X-Tenant: ac\x00me", 'admin.ai.headers.value'];
        yield 'invalid utf-8'            => ["X-Tenant: ac\xFFme", 'admin.ai.headers.value'];
        yield 'the same name twice'      => ["X-Tenant: a\nx-tenant: b", 'admin.ai.headers.duplicate'];
        yield 'the client\'s own header' => ['Content-Type: text/plain', 'admin.ai.headers.reserved'];
        yield 'where a proxy routes it'  => ['host: internal.example', 'admin.ai.headers.reserved'];
        yield 'a second length'          => ['Content-Length: 0', 'admin.ai.headers.reserved'];
        yield 'too many'                 => [implode("\n", array_map(static fn (int $n): string => "X-H{$n}: v", range(1, 21))), 'admin.ai.headers.too_many'];
    }

    /**
     * Refused while the person who typed it is still looking — and with a
     * reason that is a key, never the text. The text is a credential.
     */
    #[DataProvider('refused')]
    public function testWhatCannotBeSentAsWrittenIsRefused(string $typed, string $reason): void
    {
        try {
            ConnectionHeaders::parse($typed);

            self::fail('accepted');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /**
     * A line break inside a value would be a second header of somebody else's
     * choosing. It cannot be typed — the line break ends the line — so what is
     * left is a second line, and that line is judged on its own.
     */
    public function testALineBreakCannotSmuggleAHeaderIn(): void
    {
        $this->expectExceptionMessage('admin.ai.headers.reserved');

        ConnectionHeaders::parse("X-Tenant: acme\r\nHost: internal.example");
    }

    public function testWhatIsStoredReadsBackAsItWasTyped(): void
    {
        $headers = ['X-Tenant' => 'acme', 'Authorization' => 'Basic dXNlcjpwYXNz'];

        self::assertSame($headers, ConnectionHeaders::decode(ConnectionHeaders::encode($headers)));
        self::assertSame(['X-Tenant', 'Authorization'], ConnectionHeaders::names(ConnectionHeaders::encode($headers)));
        self::assertNull(ConnectionHeaders::encode([]), 'no headers is one state, not an empty object in a column');
    }

    /**
     * Reading never throws. It runs in front of every request to the model, and
     * a column that cannot be read must cost the extra headers and nothing
     * else.
     */
    public function testAnUnreadableColumnIsNoHeadersRatherThanAnError(): void
    {
        self::assertSame([], ConnectionHeaders::decode(null));
        self::assertSame([], ConnectionHeaders::decode(''));
        self::assertSame([], ConnectionHeaders::decode('not json'));
        self::assertSame([], ConnectionHeaders::decode('"a string"'));
    }

    /**
     * A restored backup writes the column without the form. Whatever in it the
     * form would have refused is left out on the way to the wire.
     */
    public function testAStoredHeaderTheFormWouldRefuseIsNotSent(): void
    {
        $stored = (string) json_encode([
            'X-Tenant' => 'acme',
            'Host'     => 'internal.example',
            'X-Bad'    => "two\r\nlines",
            'X-Number' => 7,
        ]);

        self::assertSame(['X-Tenant' => 'acme'], ConnectionHeaders::decode($stored));
    }

    // ── what a connection sends ──────────────────────────────────────────────

    public function testAConnectionSendsItsTokenAndItsExtraHeaders(): void
    {
        $connection = new AiConnection('http://10.0.0.5:11434', 'a-token', headers: ['X-Tenant' => 'acme']);

        self::assertSame(['Authorization' => 'Bearer a-token', 'X-Tenant' => 'acme'], $connection->requestHeaders());
    }

    /**
     * One Authorization, and it is the typed one. Two on a request is a request
     * different proxies read differently.
     */
    public function testATypedAuthorizationReplacesTheBearerWhateverItsCase(): void
    {
        $connection = new AiConnection('http://10.0.0.5:11434', 'a-token', headers: ['authorization' => 'Basic dXNlcjpwYXNz']);

        self::assertSame(['authorization' => 'Basic dXNlcjpwYXNz'], $connection->requestHeaders());
    }

    public function testNothingToShowIsNoHeadersAtAll(): void
    {
        self::assertSame([], new AiConnection('http://10.0.0.5:11434')->requestHeaders());
    }
}
