<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Entity\User\ApiToken;
use App\Entity\User\User;

/**
 * Mutable per-request state threaded through every method call:
 *  - the authenticated user (used to scope and validate accountId);
 *  - the accumulated method responses, so a later call can resolve a
 *    back-reference (e.g. "#ids") against an earlier call's result;
 *  - the createdIds map (client-supplied creation ids plus any minted
 *    server-side during this request), so "#creationId" references resolve;
 *  - the app password the request authenticated with, when it used one. Null
 *    means the browser session, which is the user themselves and is never
 *    limited by ApiTokenScope.
 */
final class JmapContext
{
    /** @var list<array{0:string,1:array<string,mixed>,2:string}> */
    private array $responses = [];

    /** @var array<string,string> */
    private array $createdIds;

    /** @var list<array{0:string,1:array<string,mixed>}> */
    private array $implicit = [];

    /**
     * @param array<string,string> $createdIds
     */
    public function __construct(
        public readonly User $user,
        array $createdIds = [],
        /**
         * The app password this request authenticated with, or null for the
         * browser session.
         *
         * Read only by JmapProcessor's scope check. Everything else keeps
         * working from $user, which is why this is an addition rather than a
         * replacement: a JMAP method should not have to know how its caller
         * proved who they were.
         */
        public readonly ?ApiToken $token = null,
    ) {
        $this->createdIds = $createdIds;
    }

    /**
     * @param array{0:string,1:array<string,mixed>,2:string} $response
     */
    public function addResponse(array $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * @return list<array{0:string,1:array<string,mixed>,2:string}>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    /**
     * A response for a call the client did not make but the running method
     * performed on its behalf — RFC 8621 §7.5's implicit Email/set after an
     * EmailSubmission/set with onSuccessUpdateEmail. The processor appends it
     * after the method's own response, under the same callId, and discards it
     * if the method fails.
     *
     * @param array<string,mixed> $result
     */
    public function addImplicitResponse(string $name, array $result): void
    {
        $this->implicit[] = [$name, $result];
    }

    /**
     * @return list<array{0:string,1:array<string,mixed>}>
     */
    public function drainImplicitResponses(): array
    {
        $implicit = $this->implicit;
        $this->implicit = [];

        return $implicit;
    }

    public function recordCreatedId(string $creationId, string $realId): void
    {
        $this->createdIds[$creationId] = $realId;
    }

    public function resolveCreationId(string $creationId): ?string
    {
        return $this->createdIds[$creationId] ?? null;
    }

    /**
     * RFC 8620 §5.3 allows "#creationId" anywhere an Id is expected, referring
     * to an object created earlier in the same request. Ids that do not start
     * with "#" pass through untouched.
     *
     * Returns null for an unknown creation id so callers can report it the way
     * their method requires (notFound, invalidProperties, ...).
     */
    public function resolveId(string $id): ?string
    {
        if (false === str_starts_with($id, '#')) {
            return $id;
        }

        return $this->resolveCreationId(substr($id, 1));
    }

    /**
     * @return array<string,string>
     */
    public function createdIds(): array
    {
        return $this->createdIds;
    }
}
