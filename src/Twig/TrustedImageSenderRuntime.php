<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\TrustedImageSender;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSender\TrustedImageSenderRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The reading half of TrustedImageSenderExtension (#807).
 */
final readonly class TrustedImageSenderRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private TrustedImageSenderRepository $senders,
        private Security                     $security,
    ) {}

    /**
     * The signed-in user's entries, domains first.
     *
     * @return list<TrustedImageSender>
     */
    public function entries(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->senders->findForUser($user) : [];
    }
}
