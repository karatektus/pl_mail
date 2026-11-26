<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `twenty_person_url()` — where an address's person is in the Twenty CRM named by TWENTY_URL.
 *
 * The link is Twenty's People list filtered to the address, which Twenty
 * builds from the URL, so plMail needs no key and makes no request.
 */
final class TwentyExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%env(default::TWENTY_URL)%')]
        private readonly ?string $twentyUrl,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('twenty_person_url', $this->personUrl(...)),
        ];
    }

    public function personUrl(?string $address): ?string
    {
        $base    = rtrim(trim((string) $this->twentyUrl), '/');
        $address = trim((string) $address);

        if ('' === $base || '' === $address) {
            return null;
        }

        return $base . '/objects/people?' . http_build_query(['filter' => ['emails' => ['CONTAINS' => $address]]]);
    }
}
