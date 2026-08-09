<?php

declare(strict_types=1);

namespace App\Tests\Security\TwoFactor;

use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use App\Repository\User\TrustedDeviceRepository;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Remember this device" over more than one login.
 *
 * Driven through the real login forms rather than against the manager, because
 * the bug this pins was not in the manager's own logic — it was in who calls it.
 * scheb asks the trusted-device manager to add a device from two places, and the
 * second one fires on every subsequent login of an *already trusted* browser
 * (that is what `extend_lifetime` means to a manager whose grant is a signed
 * cookie: reissue it with a later expiry). A manager whose grant is a row read
 * that as "insert another row", so one browser grew a new line in Settings →
 * Security every time its owner signed in.
 *
 * Only a test that logs in twice can see it. Anything narrower asserts one
 * addTrustedDevice() call and passes against the broken code.
 */
final class TrustedDeviceLifecycleTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /**
     * Reboot is disabled so every request runs against the one container whose
     * connection holds the transaction below; a rebooted kernel would open a
     * second connection that cannot see the user this test just created.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user = $this->enrolledUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The claim: signing in on a browser that is already trusted keeps the one
     * grant it already has. Before the fix this test found two rows — one from
     * the enrolling login, one minted by the trusted-device condition on the
     * second — and a real browser accrued one more every day.
     */
    public function testSigningInAgainOnATrustedBrowserDoesNotMintASecondGrant(): void
    {
        $this->signInThroughTwoFactor(remember: true);

        self::assertCount(1, $this->grants(), 'the enrolling login should leave exactly one grant');

        $this->signOut();
        $this->signInWithPassword();

        self::assertNotSame(
            '/2fa',
            $this->currentPath(),
            'the trusted cookie did not skip the code prompt, so remembering never took effect',
        );
        self::assertCount(
            1,
            $this->grants(),
            'signing in on an already-trusted browser minted a second grant for the same browser',
        );

        // A third, because one extra row could be an off-by-one; a row per
        // login is the shape the owner actually saw.
        $this->signOut();
        $this->signInWithPassword();

        self::assertCount(1, $this->grants(), 'grants accumulate one per login');
    }

    /**
     * The other way back in, and the one that made rows appear in same-minute
     * pairs: a browser reopened with its session gone re-authenticates from the
     * remember-me cookie, with no form involved. That runs the same
     * trusted-device condition, so before the fix it minted a row too — and a
     * user who then signed out and back in got two rows a minute apart.
     */
    public function testReturningOnARememberMeCookieDoesNotMintAnotherGrant(): void
    {
        $this->signInThroughTwoFactor(remember: true);
        self::assertCount(1, $this->grants());

        $this->returnWithRememberMeOnly();

        self::assertSame('/mail/inbox', $this->currentPath(), 'remember-me did not sign the browser back in');
        self::assertCount(1, $this->grants(), 'a remember-me re-authentication minted a second grant');
    }

    /**
     * Renewal is the point of `extend_lifetime`, and it has to reach the cookie
     * as well as the row. Extending only the row leaves a browser whose cookie
     * lapses first — back to the code prompt, with a live grant still listed.
     */
    public function testRenewalPushesTheExpiryOutAndKeepsTheSameSecret(): void
    {
        $this->signInThroughTwoFactor(remember: true);

        $before = $this->grants()[0];
        $issued = $before->expiresAt;
        $secret = $this->client->getCookieJar()->get('plmail_trusted_device')?->getValue();

        // Wind the row back so a renewal has somewhere to move it to.
        $this->em->getConnection()->executeStatement(
            'UPDATE trusted_device SET expires_at = :expiry WHERE id = :id',
            ['expiry' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'), 'id' => $before->id],
        );

        $this->signOut();
        $this->signInWithPassword();

        $after = $this->grants();

        self::assertCount(1, $after);
        self::assertSame($before->id, $after[0]->id, 'renewal replaced the grant instead of extending it');
        self::assertEqualsWithDelta(
            $issued->getTimestamp(),
            $after[0]->expiresAt->getTimestamp(),
            60,
            'the grant was not pushed back out to a full lifetime',
        );
        self::assertSame(
            $secret,
            $this->client->getCookieJar()->get('plmail_trusted_device')?->getValue(),
            'the browser was handed a different secret for the grant it already held',
        );
    }

    /**
     * The cookie has to actually reach the browser, or every login is a fresh
     * challenge and the row it left behind is dead weight. Asserted on the
     * response of the request that mints it: the grant is decided deep inside
     * authentication, where there is no Response yet, and the redirect that
     * finishes 2FA is the one carrying it out.
     */
    public function testTheGrantIsHandedToTheBrowserOnTheRedirectThatFinishesTwoFactor(): void
    {
        $this->signInThroughTwoFactor(remember: true);

        self::assertNotNull(
            $this->client->getCookieJar()->get('plmail_trusted_device'),
            'the trusted-device cookie never reached the browser',
        );
    }

    /**
     * Reuse is keyed on the secret the browser presents, not on what the
     * browser looks like. Two machines behind one NAT address report the same
     * user agent and the same client IP, and collapsing them would silently
     * trust a computer nobody ticked the box on.
     */
    public function testASecondBrowserGetsItsOwnGrant(): void
    {
        $this->signInThroughTwoFactor(remember: true);
        self::assertCount(1, $this->grants());

        // A different browser is a different cookie jar and nothing else: same
        // user agent, same address, same account.
        $this->client->getCookieJar()->clear();
        $this->signInThroughTwoFactor(remember: true);

        self::assertCount(2, $this->grants(), 'a second machine behind the same address was folded into the first');
    }

    /**
     * Revoking is what the whole table is for, so re-trusting after a revoke
     * has to be a new grant rather than a quiet resurrection of the old row.
     */
    public function testARevokedGrantIsNotReusedByALaterLogin(): void
    {
        $this->signInThroughTwoFactor(remember: true);

        $grants = $this->grants();
        self::assertCount(1, $grants);
        $revoked = $grants[0]->id;

        $grants[0]->revoke();
        $this->em->flush();

        $this->signOut();
        $this->signInThroughTwoFactor(remember: true);

        $live = $this->grants();

        self::assertCount(1, $live, 'the revoked grant should not count, and the new one should');
        self::assertNotSame($revoked, $live[0]->id, 'a revoked grant was brought back to life');
    }

    /**
     * Not ticking the box leaves nothing behind. Worth stating because the
     * reuse path added for this bug runs before the checkbox is consulted in
     * one of scheb's two call sites, and a reuse that ignored it would trust
     * every browser that ever held a cookie.
     */
    public function testDecliningToRememberLeavesNoGrant(): void
    {
        $this->signInThroughTwoFactor(remember: false);

        self::assertCount(0, $this->grants());
    }

    // ── driving the forms ─────────────────────────────────────────────────────

    /**
     * Password, then the code form, arriving signed in.
     *
     * Redirects are followed throughout: the password POST lands on the target
     * page, which bounces to /2fa because the half-authenticated token does not
     * hold ROLE_USER yet. Stopping at the first hop would read that bounce as
     * the destination.
     */
    private function signInThroughTwoFactor(bool $remember): void
    {
        $this->signInWithPassword();

        self::assertSame('/2fa', $this->currentPath(), 'expected the code prompt');

        $fields = [
            '_auth_code' => TOTP::create($this->user->totpSecret, 30, 'sha1', 6)->now(),
        ];

        if (true === $remember) {
            $fields['_trusted'] = '1';
        }

        $this->follow(fn () => $this->client->request('POST', '/2fa_check', $fields));
    }

    /**
     * The first factor only. On a trusted browser this is the whole login, and
     * it is the request the duplicate grant was minted on.
     *
     * "Keep me logged in" is ticked because the real form ships it checked, and
     * the remember-me cookie it issues is a second way into the code below.
     */
    private function signInWithPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->follow(fn () => $this->client->request('POST', '/login', [
            'email'        => $this->user->email,
            'password'     => self::PASSWORD,
            '_csrf_token'  => $token,
            '_remember_me' => '1',
        ]));
    }

    /**
     * Come back with the session gone but the remember-me cookie intact — a
     * browser reopened the next morning. This authenticates with no form at
     * all, and it reaches the trusted-device manager by the same route a
     * password login does.
     */
    private function returnWithRememberMeOnly(): void
    {
        $this->client->getCookieJar()->expire('MOCKSESSID');

        $this->follow(fn () => $this->client->request('GET', '/mail/inbox'));
    }

    private function signOut(): void
    {
        $this->follow(fn () => $this->client->request('GET', '/logout'));
    }

    private function follow(callable $request): void
    {
        $this->client->followRedirects(true);

        try {
            $request();
        } finally {
            $this->client->followRedirects(false);
        }
    }

    private function currentPath(): string
    {
        return (string) parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH);
    }

    /**
     * Straight from the database: the point of the test is how many rows exist,
     * and an identity map that already holds them would answer from memory.
     *
     * @return list<TrustedDevice>
     */
    private function grants(): array
    {
        $this->em->flush();
        $this->em->clear();

        return static::getContainer()->get(TrustedDeviceRepository::class)->findActiveForUser($this->user);
    }

    private function enrolledUser(): User
    {
        $user = new User();
        $user->email = 'trusted-'.bin2hex(random_bytes(6)).'@plmail.test';
        $user->nameFirst = 'Trusted';
        $user->nameLast = 'Device';
        $user->password = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::PASSWORD);

        $this->em->persist($user);
        $this->em->flush();

        $enrolment = static::getContainer()->get(TwoFactorEnrolment::class);
        $enrolment->begin($user);
        $enrolment->confirm($user, TOTP::create($user->totpSecret, 30, 'sha1', 6)->now());

        return $user;
    }
}
