<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

/**
 * A JMAP response envelope (RFC 8620 §3.4).
 */
final class JmapResponse
{
    /**
     * @param list<array{0:string,1:array<string,mixed>,2:string}> $methodResponses
     * @param array<string,string>                                 $createdIds
     */
    public function __construct(
        public private(set) array $methodResponses,
        public private(set) string $sessionState,
        public private(set) array $createdIds = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'methodResponses' => $this->methodResponses,
            'sessionState' => $this->sessionState,
        ];

        if (count($this->createdIds) > 0) {
            $payload['createdIds'] = $this->createdIds;
        }

        return $payload;
    }
}
