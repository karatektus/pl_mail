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

    public function addTrustedDevice(object $user, string $firewallName): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (false === $user instanceof User || null === $request) {
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
