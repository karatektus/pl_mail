<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Repository\Crypto\SmimeCertificateRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether the signed-in person holds an S/MIME certificate of their own —
 * what decides if an S/MIME-encrypted message is handed to the browser to
 * open (mail/_message_body.html.twig), or only said to be unreadable here.
 *
 * Any certificate, retired and expired ones included: those are kept to open
 * old mail. Whether this particular message was encrypted to one of them only
 * the browser can tell, once it has the keyring open.
 */
final class SmimeExtension extends AbstractExtension
{
    public function __construct(
        private readonly SmimeCertificateRepository $certificates,
        private readonly Security                   $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_smime_certificate', $this->hasCertificate(...)),
        ];
    }

    public function hasCertificate(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && true === $this->certificates->hasAny($user);
    }
}
