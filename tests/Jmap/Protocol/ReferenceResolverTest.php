<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Protocol;

use App\Entity\User\User;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Protocol\ReferenceResolver;
use PHPUnit\Framework\TestCase;

/**
 * A result reference names a response by callId AND name (RFC 8620 §3.7).
 *
 * One call can answer with several responses — EmailSubmission/set is followed
 * by its implicit Email/set under the same callId — and the search used to stop
 * at the first response with the right callId, so a reference to the second
 * one could never resolve.
 */
final class ReferenceResolverTest extends TestCase
{
    public function testASecondResponseUnderTheSameCallIdCanBeReferenced(): void
    {
        $context = new JmapContext(new User());
        $context->addResponse(['EmailSubmission/set', ['created' => ['s1' => ['id' => '5']]], 'c0']);
        $context->addResponse(['Email/set', ['updated' => ['5' => null]], 'c0']);

        $resolved = (new ReferenceResolver())->resolve([
            '#ids' => ['resultOf' => 'c0', 'name' => 'Email/set', 'path' => '/updated'],
        ], $context);

        self::assertSame(['ids' => ['5' => null]], $resolved);
    }
}
