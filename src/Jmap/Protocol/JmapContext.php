<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Entity\User\User;

/**
 * Mutable per-request state threaded through every method call:
 *  - the authenticated user (used to scope and validate accountId);
 *  - the accumulated method responses, so a later call can resolve a
 *    back-reference (e.g. "#ids") against an earlier call's result;
 *  - the createdIds map (client-supplied creation ids plus any minted
 *    server-side during this request), so "#creationId" references resolve.
 */
final class JmapContext
{
    /** @var list<array{0:string,1:array<string,mixed>,2:string}> */
    private array $responses = [];

    /** @var array<string,string> */
    private array $createdIds;

    /**
     * @param array<string,string> $createdIds
     */
    public function __construct(
        public readonly User $user,
        array $createdIds = [],
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
