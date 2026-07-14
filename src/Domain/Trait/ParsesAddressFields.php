<?php

declare(strict_types=1);

namespace App\Domain\Trait;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads compose_to[], compose_cc[], compose_bcc[] values posted by Tom Select
 * (each value is a plain email address string) and converts them to the
 * [{name, address}] array format used by Message::setToAddresses() etc.
 */
trait ParsesAddressFields
{
    /**
     * @return array<array{name: string, address: string}>
     */
    private function parseAddressField(Request $request, string $field): array
    {
        $values = $request->request->all($field);

        return array_values(array_filter(
            array_map(static function (string $raw): array {
                $raw = trim($raw);

                // Handle "Name <email>" format that Tom Select may submit
                if (preg_match('/^(.+?)\s*<([^>]+)>$/', $raw, $m)) {
                    return ['name' => trim($m[1]), 'address' => strtolower(trim($m[2]))];
                }

                return ['name' => '', 'address' => strtolower($raw)];
            }, $values),
            static fn(array $a): bool => $a['address'] !== '',
        ));
    }
}
