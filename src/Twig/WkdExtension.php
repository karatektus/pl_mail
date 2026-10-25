<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Crypto\WkdEntry;
use App\Entity\User\User;
use App\Service\Crypto\WkdDirectory;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the "Web Key Directory" card on Settings → Encryption shows: whether
 * the installation publishes keys at all, for which domains, and the signed-in
 * person's addresses on them. See WkdDirectory.
 */
final class WkdExtension extends AbstractExtension
{
    public function __construct(
        private readonly WkdDirectory $directory,
        private readonly Security     $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('wkd_settings', $this->settings(...)),
        ];
    }

    /**
     * @return array{enabled: bool, domains: list<string>, entries: list<WkdEntry>}
     */
    public function settings(): array
    {
        $user = $this->security->getUser();

        return [
            'enabled' => $this->directory->isEnabled(),
            'domains' => $this->directory->domains(),
            'entries' => $user instanceof User ? $this->directory->entriesFor($user) : [],
        ];
    }
}
