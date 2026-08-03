<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Core;

use App\Entity\User\PushSubscription;
use App\Jmap\Method\Core\PushSubscriptionGetMethod;
use App\Jmap\Method\Core\PushSubscriptionSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\User\PushSubscriptionRepository;
use App\Tests\Jmap\JmapTestCase;

/**
 * "PushSubscription/set" — the endpoint that decides whether this server is a
 * push relay for strangers.
 *
 * Registering a subscription tells plMail to POST to a URL the caller chose,
 * on every state change, forever. The verification handshake (RFC 8620 §7.2.2)
 * is what makes that safe: nothing is delivered until the caller proves it can
 * also *read* what arrives at that URL. Most of these tests are about the
 * refusals that keep the handshake meaningful, because a handshake with a hole
 * in it is worse than none — it looks like security.
 *
 * The keys are the non-obvious half. RFC 8620 marks `keys` optional and this
 * server requires it, because the Web Push library drops the payload without
 * encryption keys and sends a bodiless POST. A bodiless push cannot carry the
 * verification code, so an optional-keys subscription could never become
 * deliverable and nothing would say why.
 */
final class PushSubscriptionSetMethodTest extends JmapTestCase
{
    private PushSubscriptionSetMethod $method;
    private PushSubscriptionGetMethod $get;
    private PushSubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->method        = $container->get(PushSubscriptionSetMethod::class);
        $this->get           = $container->get(PushSubscriptionGetMethod::class);
        $this->subscriptions = $container->get(PushSubscriptionRepository::class);
    }

    public function testItIsNamedPushSubscriptionSet(): void
    {
        self::assertSame('PushSubscription/set', $this->method->name());
    }

    // ── create ────────────────────────────────────────────────────────────

    public function testCreateRegistersAnUnverifiedSubscription(): void
    {
        $result = $this->handle(['create' => ['s1' => $this->validCreate()]]);

        $created = (array) $result['created'];

        self::assertArrayHasKey('s1', $created);

        $subscription = $this->find($created['s1']['id']);

        self::assertSame('https://push.example.test/endpoint', $subscription->url);
        self::assertFalse($subscription->verified, 'a fresh subscription must not be deliverable');
        self::assertNotNull($subscription->verificationCode);
    }

    /**
     * The spec lets a server shorten the requested expiry; this one does not,
     * so the echo has to be the value that was asked for or a client would
     * re-register on a schedule the server never set.
     */
    public function testCreateEchoesTheRequestedExpiryUnchanged(): void
    {
        $result = $this->handle([
            'create' => ['s1' => ['expires' => '2030-01-02T03:04:05Z'] + $this->validCreate()],
        ]);

        self::assertSame('2030-01-02T03:04:05Z', ((array) $result['created'])['s1']['expires']);
    }

    /**
     * @return iterable<string, array{array<string,mixed>}>
     */
    public static function rejectedCreates(): iterable
    {
        yield 'no deviceClientId' => [['deviceClientId' => null]];
        yield 'blank deviceClientId' => [['deviceClientId' => '   ']];
        yield 'no url' => [['url' => null]];
        yield 'relative url' => [['url' => '/endpoint']];
        yield 'non-http scheme' => [['url' => 'ftp://push.example.test/e']];
        yield 'no keys at all' => [['keys' => null]];
        yield 'only p256dh' => [['keys' => ['p256dh' => 'a-key']]];
        yield 'only auth' => [['keys' => ['auth' => 'a-secret']]];
        yield 'empty p256dh' => [['keys' => ['p256dh' => '', 'auth' => 'a-secret']]];
        yield 'types not an array' => [['types' => 'Email']];
        yield 'unparseable expires' => [['expires' => 'next tuesday-ish']];
    }

    /**
     * @param array<string,mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedCreates')]
    public function testAnUnusableSubscriptionIsRefusedRatherThanStoredDead(array $override): void
    {
        $result = $this->handle(['create' => ['s1' => $override + $this->validCreate()]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['s1']['type']);
        self::assertCount(0, $this->subscriptions->findForUser($this->user));
    }

    /**
     * A `javascript:` URL passes nothing this server would want to POST to, and
     * the scheme check is the only thing between it and a request the server
     * makes on the caller's behalf.
     */
    public function testOnlyHttpAndHttpsUrlsAreAccepted(): void
    {
        $result = $this->handle([
            'create' => ['s1' => ['url' => 'javascript:alert(1)'] + $this->validCreate()],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['s1']['type']);
    }

    /**
     * deviceClientId is stable per device+app, so a reinstall replaces its own
     * row. Accumulating one dead endpoint per install would mean a push fanning
     * out to every phone the user ever owned.
     */
    public function testReRegisteringADeviceReplacesItsSubscription(): void
    {
        $first = $this->handle(['create' => ['s1' => $this->validCreate()]]);
        $firstId = ((array) $first['created'])['s1']['id'];
        $firstCode = $this->find($firstId)->verificationCode;

        $second = $this->handle([
            'create' => ['s2' => ['url' => 'https://push.example.test/moved'] + $this->validCreate()],
        ]);

        self::assertSame($firstId, ((array) $second['created'])['s2']['id']);
        self::assertCount(1, $this->subscriptions->findForUser($this->user));
        self::assertSame('https://push.example.test/moved', $this->find($firstId)->url);
        self::assertNotSame($firstCode, $this->find($firstId)->verificationCode, 'a new endpoint must redo the handshake');
    }

    public function testACreateThatIsNotAnObjectIsRefusedForThatIdAlone(): void
    {
        $result = $this->handle(['create' => ['s1' => 'a string']]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['s1']['type']);
    }

    public function testANonObjectCreateArgumentFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['create' => 'not a map']);
    }

    // ── the handshake ─────────────────────────────────────────────────────

    public function testEchoingTheVerificationCodeMakesTheSubscriptionDeliverable(): void
    {
        $subscription = $this->created();

        $result = $this->handle([
            'update' => [(string) $subscription->id => ['verificationCode' => (string) $subscription->verificationCode]],
        ]);

        self::assertSame([(string) $subscription->id => null], (array) $result['updated']);
        self::assertTrue($subscription->verified);
        self::assertNull($subscription->verificationCode, 'a spent code must not be reusable');
    }

    /**
     * The one that matters. If a wrong code verified, the handshake would be a
     * formality and anyone could aim this server's POSTs at a third party.
     */
    public function testAWrongVerificationCodeLeavesTheSubscriptionUndeliverable(): void
    {
        $subscription = $this->created();

        $result = $this->handle([
            'update' => [(string) $subscription->id => ['verificationCode' => 'not-the-code']],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $subscription->id]['type']);
        self::assertFalse($subscription->verified);
        self::assertNotNull($subscription->verificationCode, 'a failed attempt must not burn the code');
    }

    public function testANonStringVerificationCodeIsRefused(): void
    {
        $subscription = $this->created();

        $result = $this->handle([
            'update' => [(string) $subscription->id => ['verificationCode' => 12345]],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $subscription->id]['type']);
        self::assertFalse($subscription->verified);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function testExpiresAndTypesAreThePropertiesAnUpdateMayChange(): void
    {
        $subscription = $this->created();

        $this->handle([
            'update' => [(string) $subscription->id => [
                'expires' => '2031-06-01T00:00:00Z',
                'types' => ['Email', 'Mailbox'],
            ]],
        ]);

        self::assertSame(['Email', 'Mailbox'], $subscription->types);
        self::assertSame('2031-06-01', $subscription->expires?->format('Y-m-d'));
    }

    /**
     * url and keys are create-only. Repointing a verified subscription at a new
     * endpoint would carry the verification across to a URL that never proved
     * anything, which is the handshake defeated in one patch.
     */
    public function testTheUrlCannotBeChangedByAnUpdate(): void
    {
        $subscription = $this->created();

        $result = $this->handle([
            'update' => [(string) $subscription->id => ['url' => 'https://elsewhere.example.test/e']],
        ]);

        self::assertSame('invalidPatch', ((array) $result['notUpdated'])[(string) $subscription->id]['type']);
        self::assertSame('https://push.example.test/endpoint', $subscription->url);
    }

    public function testAnUnknownSubscriptionIsNotUpdated(): void
    {
        $result = $this->handle(['update' => ['999999' => ['types' => null]]]);

        self::assertSame('notFound', ((array) $result['notUpdated'])['999999']['type']);
    }

    /** Subscriptions are per user, so another user's id must not resolve. */
    public function testAnotherUsersSubscriptionIsNotFound(): void
    {
        $subscription = $this->created();

        $stranger = $this->strangersContext();

        $result = $this->method->handle(
            ['update' => [(string) $subscription->id => ['types' => null]]],
            $stranger,
        );

        self::assertSame('notFound', ((array) $result['notUpdated'])[(string) $subscription->id]['type']);
    }

    // ── destroy ───────────────────────────────────────────────────────────

    public function testDestroyRemovesTheSubscription(): void
    {
        $subscription = $this->created();
        $id = (string) $subscription->id;

        $result = $this->handle(['destroy' => [$id]]);

        self::assertSame([$id], $result['destroyed']);
        self::assertCount(0, $this->subscriptions->findForUser($this->user));
    }

    public function testAnUnknownSubscriptionIsNotDestroyed(): void
    {
        $result = $this->handle(['destroy' => ['999999']]);

        self::assertSame('notFound', ((array) $result['notDestroyed'])['999999']['type']);
    }

    // ── get ───────────────────────────────────────────────────────────────

    /**
     * The secrets are write-only. Echoing the keys or the code back would let
     * anyone who can read one response forge pushes to that device — or verify
     * a subscription they registered against somebody else's endpoint.
     */
    public function testGetNeverReturnsTheKeysOrTheVerificationCode(): void
    {
        $this->created();

        $result = $this->get->handle([], $this->context());

        $entry = $result['list'][0];

        self::assertArrayNotHasKey('keys', $entry);
        self::assertArrayNotHasKey('p256dh', $entry);
        self::assertArrayNotHasKey('auth', $entry);
        self::assertArrayNotHasKey('verificationCode', $entry);
        self::assertSame('https://push.example.test/endpoint', $entry['url']);
    }

    public function testGetReportsUnknownIdsAsNotFound(): void
    {
        $subscription = $this->created();

        $result = $this->get->handle(
            ['ids' => [(string) $subscription->id, '999999']],
            $this->context(),
        );

        self::assertCount(1, $result['list']);
        self::assertSame(['999999'], $result['notFound']);
    }

    public function testGetShowsOnlyTheAuthenticatedUsersSubscriptions(): void
    {
        $this->created();

        $result = $this->get->handle([], $this->strangersContext());

        self::assertSame([], $result['list']);
    }

    public function testGetRefusesANonArrayIdsArgument(): void
    {
        $this->expectException(MethodException::class);

        $this->get->handle(['ids' => 'one-id'], $this->context());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->method->handle($arguments, $this->context());
    }

    /**
     * A registered, still-unverified subscription — the state every client is
     * in between its create and its verifying update.
     */
    private function created(): PushSubscription
    {
        $result = $this->handle(['create' => ['s1' => $this->validCreate()]]);

        return $this->find(((array) $result['created'])['s1']['id']);
    }

    private function find(string $id): PushSubscription
    {
        $subscription = $this->subscriptions->findOneOwnedBy((int) $id, $this->user);

        self::assertNotNull($subscription);

        return $subscription;
    }

    /**
     * @return array<string,mixed>
     */
    private function validCreate(): array
    {
        return [
            'deviceClientId' => 'a-phone',
            'url' => 'https://push.example.test/endpoint',
            'keys' => ['p256dh' => 'a-public-key', 'auth' => 'a-secret'],
        ];
    }

    /**
     * A second, unrelated user. Built here rather than in the base fixture
     * because only the scoping tests need one.
     */
    private function strangersContext(): \App\Jmap\Protocol\JmapContext
    {
        $stranger = new \App\Entity\User\User();
        $stranger->email = 'stranger-'.uniqid('', true).'@example.test';
        $stranger->nameFirst = 'Push';
        $stranger->nameLast = 'Stranger';
        $stranger->roles = ['ROLE_USER'];
        $stranger->password = 'x';
        $this->em->persist($stranger);
        $this->em->flush();

        return new \App\Jmap\Protocol\JmapContext($stranger);
    }
}
