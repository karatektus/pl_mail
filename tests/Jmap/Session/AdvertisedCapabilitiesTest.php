<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\JmapProcessor;
use App\Jmap\Protocol\JmapRequest;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * Every capability the Session advertises must be one a client may declare in
 * "using".
 *
 * The obvious way for a client to fill "using" is to copy the capabilities out
 * of the Session. Anything advertised there but missing from
 * Capability::SUPPORTED turns that into an unknownCapability on the whole
 * request — every call refused, not just the extension lost.
 * urn:plmail:params:jmap:push was exactly that: advertised since Web Push came
 * in, never declarable. The per-capability tests (SyncWindowTest and friends)
 * each guard one URN; this one guards the next URN somebody adds.
 */
final class AdvertisedCapabilitiesTest extends JmapTestCase
{
    public function testEveryAdvertisedCapabilityIsDeclarable(): void
    {
        $advertised = $this->advertised();

        self::assertNotEmpty($advertised);

        foreach ($advertised as $urn) {
            self::assertContains($urn, Capability::SUPPORTED, sprintf('"%s" is advertised in the Session but refused in "using".', $urn));
        }
    }

    public function testARequestUsingEveryAdvertisedCapabilityIsAccepted(): void
    {
        $processor = self::getContainer()->get(JmapProcessor::class);

        $response = $processor->process(JmapRequest::fromArray([
            'using'       => $this->advertised(),
            'methodCalls' => [['Core/echo', ['hello' => true], 'e']],
        ]), $this->user);

        self::assertSame([['Core/echo', ['hello' => true], 'e']], $response->methodResponses);
    }

    /**
     * The top-level capabilities and every account's, deduplicated.
     *
     * @return list<string>
     */
    private function advertised(): array
    {
        $this->secondAccount();
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);

        $urns = array_keys((array) $session['capabilities']);

        foreach ((array) $session['accounts'] as $account) {
            $urns = [...$urns, ...array_keys((array) $account['accountCapabilities'])];
        }

        return array_values(array_unique($urns));
    }
}
