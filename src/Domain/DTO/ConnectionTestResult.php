<?php

declare(strict_types=1);

namespace App\Domain\DTO;

/**
 * Outcome of an account connection probe.
 *
 * Both halves are nullable: null means "no server configured", which is a
 * valid account rather than a failure — smtpOk for one that only receives,
 * imapOk for one that only sends through a relay (#628).
 */
final class ConnectionTestResult
{
    public function __construct(
        public readonly ?bool   $imapOk,
        public readonly string  $imapMessage,
        public readonly string  $imapTarget,
        public readonly ?bool   $smtpOk,
        public readonly string  $smtpMessage,
        public readonly string  $smtpTarget,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'imap' => ['ok' => $this->imapOk, 'message' => $this->imapMessage, 'target' => $this->imapTarget],
            'smtp' => ['ok' => $this->smtpOk, 'message' => $this->smtpMessage, 'target' => $this->smtpTarget],
        ];
    }
}
