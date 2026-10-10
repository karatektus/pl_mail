<?php

declare(strict_types=1);
namespace App\Domain\Ai;

final class EmbeddingSpace
{
    public static function identity(string $provider, ?string $endpoint, ?string $model, ?string $revision = null): string
    {
        $parts = parse_url(trim((string)$endpoint));
        $url = rtrim(trim((string)$endpoint), '/');
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower($parts['scheme']);
            $port = $parts['port'] ?? null;
            $authority = strtolower($parts['host']);
            if (null !== $port && !(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) $authority .= ':' . $port;
            $url = $scheme . '://' . $authority . rtrim($parts['path'] ?? '', '/');
        }
        return 'v1:' . hash('sha256', $provider . "\0" . $url . "\0" . trim((string)$model) . (null === $revision || '' === $revision ? '' : "\0" . $revision));
    }
}
