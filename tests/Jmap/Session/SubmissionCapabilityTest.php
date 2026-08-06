<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Jmap\Mail\SubmissionEnvelope;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * What the session promises about sending, and why each half of it matters.
 *
 * maxDelayedSend is the only way a client can know that asking for a future
 * release is worth attempting — RFC 8621 §7 makes 0 mean "this server does not
 * do delayed send", which is what plMail advertised while the submission
 * method ignored the request. A client reads this number once and builds a
 * "send later" picker out of it, so it has to be the number the method
 * actually enforces rather than a second opinion about it.
 */
final class SubmissionCapabilityTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    public function testTheAccountAdvertisesAFutureSendWindow(): void
    {
        $capabilities = $this->submissionCapabilities();

        self::assertGreaterThan(0, $capabilities['maxDelayedSend'], '0 tells a client not to bother asking');
        self::assertSame(SubmissionEnvelope::MAX_HOLD_SECONDS, $capabilities['maxDelayedSend']);
    }

    /**
     * The advertised number and the refused one are the same constant, on
     * purpose: a window a client is invited into and then thrown out of is
     * worse than a smaller window.
     */
    public function testTheAdvertisedWindowIsThirtyDays(): void
    {
        self::assertSame(30 * 24 * 60 * 60, $this->submissionCapabilities()['maxDelayedSend']);
    }

    /**
     * FUTURERELEASE (RFC 4865) is how RFC 8621 §7 has a client say *when*, so
     * naming the extension is what tells it to put HOLDFOR or HOLDUNTIL in the
     * envelope rather than look for a property that does not exist.
     */
    public function testTheFutureReleaseExtensionIsNamedWithItsParameters(): void
    {
        $extensions = $this->submissionCapabilities()['submissionExtensions'];

        self::assertSame(['FUTURERELEASE' => ['HOLDFOR', 'HOLDUNTIL']], $extensions);
    }

    /**
     * @return array<string,mixed>
     */
    private function submissionCapabilities(): array
    {
        $session = $this->sessions->build($this->user);

        return $session['accounts'][$this->accountId()]['accountCapabilities'][Capability::SUBMISSION];
    }
}
