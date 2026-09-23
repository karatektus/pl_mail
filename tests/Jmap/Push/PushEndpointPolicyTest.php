<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Infrastructure\Http\ListedHosts;
use App\Jmap\Push\PushEndpointPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A push endpoint is a URL the server POSTs to on every change, so one aimed at
 * the container network is a standing SSRF. Public push services pass; private
 * addresses do not, unless an administrator named the host (a LAN ntfy).
 */
final class PushEndpointPolicyTest extends TestCase
{
    public function testAPrivateEndpointIsRefused(): void
    {
        $policy = new PushEndpointPolicy(new ListedHosts(''));

        self::assertFalse($policy->isAllowed('http://10.0.0.5:8090/upAbc'));
        self::assertFalse($policy->isAllowed('http://127.1/'));
        self::assertFalse($policy->isAllowed('https://[::ffff:169.254.169.254]/'));
        self::assertFalse($policy->isAllowed('http://localhost:2019/load'));
    }

    public function testAPublicEndpointAndAnAllowListedHostPass(): void
    {
        self::assertTrue((new PushEndpointPolicy(new ListedHosts('')))->isAllowed('https://93.184.215.14/wpush/v2/abc'));
        self::assertTrue((new PushEndpointPolicy(new ListedHosts('10.0.0.5')))->isAllowed('http://10.0.0.5:8090/upAbc'));
    }
}
