<?php

namespace App\Twig\Vendor;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class DotEnvTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('get_env', function (string $key): ?string {
                if (false === isset($_ENV[$key])) {
                    return null;
                }

                return $_ENV[$key];
            }),
        ];
    }


}