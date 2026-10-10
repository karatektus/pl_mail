<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\Uid\Uuid;

/** Opaque task identity, never derived from mail or personal data. */
final class AiTaskContext
{
    public ?string $current = null;
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.secret%')]
        private string $installationSecret = '',
    ) {}
    public function forLegacyDelivery(string $id): string
    {
        return self::forRun(hash_hmac('sha256', 'legacy-delivery:' . $id, $this->installationSecret));
    }
    public function id(): string
    {
        if (null !== $this->current) { return $this->current; }
        return Uuid::v4()->toRfc4122();
    }
    public static function forRun(string $run): string
    {
        $hex = substr(hash('sha256', $run), 0, 32); $hex[12] = '4'; $hex[16] = '8';
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
