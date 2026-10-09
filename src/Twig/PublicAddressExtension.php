<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Setup\PublicUrlSetting;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `public_address()` — where another device should be told this server is.
 *
 * For an address a person copies off the screen into something else: the JMAP
 * URL a mail app asks for. Such an address was built from the request, so it
 * was whatever the browser happened to be on — and a settings page opened at
 * `192.168.…` handed out an address that stops answering at the front door,
 * on an install whose administrator had stated the real one (#37).
 *
 * The same rule DevicePairingController applies to the address in a pairing
 * code, through the same method, so the code and the card beside it cannot
 * name two different servers.
 */
final class PublicAddressExtension extends AbstractExtension
{
    public function __construct(
        private readonly PublicUrlSetting $publicUrl,
        private readonly RequestStack     $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_address', $this->publicAddress(...)),
        ];
    }

    public function publicAddress(): string
    {
        return $this->publicUrl->forDevices(
            $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost() ?? '',
        );
    }
}
