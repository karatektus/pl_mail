<?php

declare(strict_types=1);
namespace App\Service\Gmail;
final class GmailBatchRetryPolicy
{
    public const int MAX_RETRIES = 5;
    public static function delay(int $attempt, ?int $cooldownSeconds = null, ?int $jitterMs = null): ?int
    {
        if ($attempt >= self::MAX_RETRIES) { return null; }
        return max(min(64000, 1000 * (2 ** max(0, $attempt))), 1000 * ($cooldownSeconds ?? 0)) + ($jitterMs ?? random_int(0, 1000));
    }
}
