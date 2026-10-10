<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Header values are credentials; errors must never interpolate their contents. */
final class ConnectionHeaders
{
    /** @param array<string,string|array{mode: 'session'}> $headers @return array<string,string|array{mode: 'session'}> */
    public static function validate(array $headers): array
    {
        if (count($headers) > 20) { throw new \InvalidArgumentException('At most 20 additional headers are allowed.'); }
        $seen = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && ['mode' => 'session'] !== $value) || strlen($name) > 128
                || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)
                || (is_string($value) && (strlen($value) > 4096 || 0 !== preg_match('/[\p{Cc}\p{Cf}]/u', $value)))) {
                throw new \InvalidArgumentException('Invalid additional header name or value.');
            }
            $lower = strtolower($name);
            if (isset($seen[$lower]) || in_array($lower, ['authorization', 'proxy-authorization', 'host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'te', 'trailer', 'upgrade', 'expect', 'accept', 'accept-encoding', 'content-type', 'content-encoding', 'user-agent', 'cookie', 'set-cookie', 'forwarded', 'x-http-method-override'], true)
                || str_starts_with($lower, 'proxy-') || str_starts_with($lower, 'sec-') || str_starts_with($lower, 'x-forwarded-')) {
                throw new \InvalidArgumentException('Duplicate or managed additional header.');
            }
            $seen[$lower] = true;
        }
        return $headers;
    }

    /** @return array<string,string|array{mode: 'session'}> */
    public static function decode(?string $encryptedJson): array
    {
        if (null === $encryptedJson || '' === $encryptedJson) { return []; }
        $headers = json_decode($encryptedJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($headers)) { throw new \InvalidArgumentException('Invalid additional headers.'); }
        return self::validate($headers);
    }
}
