<?php

namespace App\Twig\Vendor;

use Symfony\Component\Translation\TranslatableMessage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigTest;

/**
 * Class PhpTwigExtension
 */
class PhpTwigExtension extends AbstractExtension
{
    /**
     * @param string $algo
     * @param string $string
     *
     * @return string
     */
    public function getFilterHash(string $string, string $algo = 'sha256'): string
    {
        return hash($algo, $string);
    }

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('hash', [$this, 'getFilterHash']),
        ];
    }

    public function getTestTranslatable(mixed $input): bool
    {
        return ($input instanceof TranslatableMessage);
    }

    public function getTests(): array
    {
        return [
            new TwigTest('translatable', [$this, 'getTestTranslatable']),
        ];
    }

}