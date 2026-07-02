<?php

namespace App\Twig;

use RuntimeException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Class AssetsTwigExtension
 */
class AssetsTwigExtension extends AbstractExtension
{

    public function getFilterFileVersion(string $fileName): string
    {
        $fileNamePath = $fileName;
        if (false === str_starts_with($fileNamePath, '/')) {
            $fileNamePath = sprintf('/%s', $fileNamePath);
        }

        $filePath = sprintf("%s/../../public%s", __DIR__, $fileNamePath);
        if (false === file_exists($filePath)) {
            throw new RuntimeException(sprintf('%s not found', $filePath));
        }

        return sprintf("%s?v=%s", $fileName, substr(md5_file($filePath), 0, 5));
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('app_assets_file_version', [$this, 'getFilterFileVersion']),
        ];
    }

    public function getFunctionModulesChecksum(): string
    {
        return md5_file(sprintf('%s/../../assets/js/module/checksum.md5', __DIR__));
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_assets_modules_checksum', [$this, 'getFunctionModulesChecksum']),
        ];
    }

}