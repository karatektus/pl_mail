<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\User\ApiTokenScope;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an app password may do.
 *
 * The rules that matter here are the ones with a wrong direction that costs
 * something: a credential that arrives with more access than intended, or a
 * scope list that a restored backup empties out. None of them need a database.
 */
final class ApiTokenScopeTest extends TestCase
{
    /**
     * A token minted with nothing granted can do nothing.
     *
     * ApiTokenScope::fromStored() returns [] for anything unrecognised, and
     * this is the same answer — the safe one. The column's default is `[]` for
     * the same reason.
     */
    public function testATokenWithNoScopesHasNone(): void
    {
        $token = $this->token([]);

        self::assertSame([], $token->grantedScopes);
        self::assertTrue($token->hasNoScopes());

        foreach (ApiTokenScope::all() as $scope) {
            self::assertFalse($token->hasScope($scope), $scope->value);
        }
    }

    public function testGrantedScopesAreWhatTheTokenHas(): void
    {
        $token = $this->token([ApiTokenScope::Read]);

        self::assertTrue($token->hasScope(ApiTokenScope::Read));
        self::assertFalse($token->hasScope(ApiTokenScope::Send));
        self::assertFalse($token->hasScope(ApiTokenScope::Delete));
    }

    /**
     * A scope list that is not a list of known scopes reads as empty.
     *
     * The column is jsonb, so a restored document, a hand edit or a value from
     * a future version can all put something else there. Dropping what is not
     * recognised means a token ends up with less access rather than more.
     */
    #[DataProvider('malformedScopes')]
    public function testAMalformedScopeListReadsAsEmpty(mixed $stored): void
    {
        // Non-array values cannot even be stored on the typed property, so
        // they are put through the reader directly — which is the code that
        // would see them after Doctrine hydrated a jsonb scalar.
        if (false === is_array($stored)) {
            self::assertSame([], ApiTokenScope::fromStored($stored));

            return;
        }

        $token = new ApiToken($this->user(), 'Malformed');
        $this->setScopes($token, $stored);

        self::assertSame([], $token->grantedScopes);
        self::assertTrue($token->hasNoScopes());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedScopes(): iterable
    {
        yield 'null'          => [null];
        yield 'a bare string' => ['read'];
        yield 'an int'        => [7];
        yield 'unknown names' => [['admin', 'root']];
    }

    /**
     * A JSON object rather than a list keeps its string values.
     *
     * Nobody writes a scope list as a map, and if one arrives this way the
     * values are still scope names. Reading them is harmless; refusing the
     * whole thing would be a rule with no failure behind it.
     */
    public function testAMapKeepsItsStringValues(): void
    {
        $token = new ApiToken($this->user(), 'Map');
        $this->setScopes($token, ['a' => 'read']);

        self::assertSame([ApiTokenScope::Read], $token->grantedScopes);
    }

    /**
     * One unknown entry does not discard the known ones beside it.
     *
     * A future version adding a fourth scope should not make an older install
     * unable to read the other three — the token keeps what it understands.
     */
    public function testAnUnknownScopeIsDroppedAndTheRestKept(): void
    {
        $token = new ApiToken($this->user(), 'Mixed');
        $this->setScopes($token, ['read', 'archive-everything', 'delete']);

        self::assertSame(
            [ApiTokenScope::Read, ApiTokenScope::Delete],
            $token->grantedScopes,
        );
    }

    public function testGrantingAndRevokingRoundTrips(): void
    {
        $token = $this->token([]);

        $token->grantScope(ApiTokenScope::Read);
        $token->grantScope(ApiTokenScope::Read); // idempotent

        self::assertSame([ApiTokenScope::Read], $token->grantedScopes);

        $token->grantScope(ApiTokenScope::Send);
        $token->revokeScope(ApiTokenScope::Read);

        self::assertSame([ApiTokenScope::Send], $token->grantedScopes);
    }

    public function testGrantingEverythingGrantsEveryCase(): void
    {
        $token = $this->token([]);
        $token->grantAllScopes();

        self::assertSame(ApiTokenScope::all(), $token->grantedScopes);
    }

    /**
     * Each scope covers the methods it claims, and read covers no write.
     *
     * The point of the split is that granting read alone cannot change
     * anything: if Email/set's destroying half were in Read's method list by
     * accident, a read-only token could empty the bin.
     */
    public function testReadGrantsNoDestructiveMethod(): void
    {
        $read = ApiTokenScope::Read->methods();

        self::assertContains('Email/get', $read);
        self::assertContains('Email/query', $read);
        self::assertContains('Mailbox/get', $read);

        self::assertNotContains('EmailSubmission/set', $read);
        self::assertNotContains('Thread/set', $read);
        self::assertNotContains('Mailbox/set', $read);

        // Email/set is decided per operation, and Read owns only the middle one.
        self::assertSame(['update'], ApiTokenScope::Read->emailSetOperations());
        self::assertSame(['create'], ApiTokenScope::Send->emailSetOperations());
        self::assertSame(['destroy'], ApiTokenScope::Delete->emailSetOperations());
    }

    /** @param list<ApiTokenScope> $scopes */
    private function token(array $scopes): ApiToken
    {
        $minted = ApiToken::create($this->user(), 'Test', $scopes);

        return $minted['token'];
    }

    private function user(): User
    {
        $user            = new User();
        $user->email     = 'scopes-' . bin2hex(random_bytes(4)) . '@plmail.test';
        $user->nameFirst = 'Scope';
        $user->nameLast  = 'Test';
        $user->password  = 'x';

        return $user;
    }

    /**
     * Writes the private jsonb column directly, which is what a restored or
     * hand-edited row would look like once Doctrine hydrated it.
     */
    private function setScopes(ApiToken $token, mixed $value): void
    {
        // A typed property will not take a string or an int at all, so the
        // "malformed" cases that are not arrays are exercised through the
        // reader's own entry point rather than by forcing the column. This is
        // what a jsonb column holding a scalar would hand back.
        if (false === is_array($value)) {
            $reflection = new \ReflectionProperty(ApiToken::class, 'scopes');
            $reflection->setValue($token, []);

            return;
        }

        $reflection = new \ReflectionProperty(ApiToken::class, 'scopes');
        $reflection->setValue($token, $value);
    }
}
