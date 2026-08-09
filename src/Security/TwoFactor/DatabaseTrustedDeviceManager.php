<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use App\Repository\User\TrustedDeviceRepository;
use App\Service\User\TwoFactor\DeviceLabeller;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Remember this device", backed by a table instead of a signed cookie.
 *
 * scheb's stock manager puts the whole grant in the cookie: a JWT holding a
 * username and a version, signed with the app secret. That is stateless and
 * fast, and it cannot be taken back — a stolen cookie stays valid for its full
 * sixty days, and the only revocation available is bumping the version, which
 * drops every device the user owns at once. For a mailbox that is the wrong
 * trade. Here the cookie is an opaque bearer secret and the grant is a row, so
 * "revoke this laptop" takes effect on the device's very next request.
 *
 * The cost is a query per request on a 2FA-enabled account. It is a single
 * indexed equality match on a table with one row per device, which is not the
 * expensive thing about serving a mailbox.
 */
final readonly class DatabaseTrustedDeviceManager implements TrustedDeviceManagerInterface
{
    public function __construct(
        private TrustedDeviceRepository $trustedDevices,
        private TrustedDeviceCookieJar $cookies,
        private DeviceLabeller $labeller,
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private int $lifetime,
        private bool $extendLifetime,
    ) {
    }

    /**
     * Whether the checkbox on the 2FA form may be honoured.
     *
     * scheb has already established that the box was ticked by the time this
     * is asked; the question left is whether this install allows it at all.
     * A lifetime of zero switches the feature off without having to unpick the
     * firewall configuration.
     */
    public function canSetTrustedDevice(object $user, Request $request, string $firewallName): bool
    {
        return $user instanceof User && $this->lifetime > 0;
    }

    /**
     * Trust this browser — reusing the grant it already holds, if it holds one.
     *
     * The reuse is not an optimisation. scheb asks for a device to be added
     * from two places, and only one of them is the checkbox: TrustedDeviceCondition
     * calls this on *every* login of an already-trusted browser, because that is
     * what `extend_lifetime` means to a manager whose grant is a signed cookie —
     * reissue it with a later expiry. A manager whose grant is a row read that
     * as "insert another row", so one browser grew a new line in Settings →
     * Security every time its owner signed in, each one orphaning the last: the
     * cookie was replaced too, so the previous row could never be presented
     * again and simply sat in the list until it expired.
     *
     * Matching on the presented secret is what keeps this honest. Two machines
     * behind one NAT address report the same user agent and the same client IP,
     * so folding rows together by how the device looks would quietly trust a
     * computer nobody ticked the box on. A cookie, nobody else has.
     */
    public function addTrustedDevice(object $user, string $firewallName): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (false === $user instanceof User || null === $request) {
            return;
        }

        if (true === $this->renew($user, $firewallName, $request)) {
            return;
        }

        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            $user,
            $firewallName,
            $this->labeller->label($request->headers->get('User-Agent')),
            $this->expiry(),
            $request->headers->get('User-Agent'),
            $request->getClientIp(),
        );

        $this->entityManager->persist($device);
        $this->entityManager->flush();

        $this->cookies->issue($secret, $device->expiresAt, $request);
    }

    public function isTrustedDevice(object $user, string $firewallName): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (false === $user instanceof User || null === $request) {
            return false;
        }

        $secret = $this->cookies->read($request);

        if (null === $secret) {
            return false;
        }

        // Scoped to the user as well as the secret: a cookie left behind by
        // whoever used this browser last must not skip the prompt for the
        // person signing in now.
        $device = $this->trustedDevices->findActiveBySecret($secret, $user, $firewallName);

        if (null === $device) {
            return false;
        }

        $this->touch($device, $request);

        return true;
    }

    /**
     * Push the grant this browser already holds out to a full lifetime again,
     * and hand the same secret back with the later expiry.
     *
     * The cookie is reissued rather than left alone because the row and the
     * cookie expire independently: extending only the row leaves a browser
     * whose cookie lapses first, which drops it back to the code prompt with a
     * live grant still listed in Settings. The secret does not rotate — it
     * cannot, since only its digest is stored, and there is nothing to rotate
     * away from: the same browser is presenting the same secret it was given.
     *
     * @return bool whether a grant was found to renew
     */
    private function renew(User $user, string $firewallName, Request $request): bool
    {
        $secret = $this->cookies->read($request);

        if (null === $secret) {
            return false;
        }

        $device = $this->trustedDevices->findReusableBySecret($secret, $user, $firewallName);

        if (null === $device) {
            return false;
        }

        $device->extendTo($this->expiry());
        $device->lastUsedAt = new DateTimeImmutable();
        $device->ipAddress = $request->getClientIp();

        $this->entityManager->flush();

        // Deliberately not relabelled. The label describes the device as it was
        // when the user trusted it; a browser that has since updated should not
        // quietly rename a row the user is being asked to recognise.
        $this->cookies->issue($secret, $device->expiresAt, $request);

        return true;
    }

    /**
     * Record the visit, and slide the expiry along if the install asked for
     * that. Both are best-effort bookkeeping on a request that has already
     * been decided, so this never fails the check.
     */
    private function touch(TrustedDevice $device, Request $request): void
    {
        $device->lastUsedAt = new DateTimeImmutable();
        $device->ipAddress = $request->getClientIp();

        if (true === $this->extendLifetime) {
            $device->extendTo($this->expiry());
        }

        $this->entityManager->flush();
    }

    private function expiry(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('+%d seconds', $this->lifetime));
    }
}
