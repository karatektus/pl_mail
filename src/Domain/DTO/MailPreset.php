<?php

declare(strict_types=1);

namespace App\Domain\DTO;

/**
 * A single IMAP/SMTP provider preset, loaded from config/mail_presets.yaml.
 *
 * Immutable reference data — never persisted, never user-editable.
 */
final class MailPreset
{
    /**
     * @param string[] $domains
     */
    public function __construct(
        public readonly string  $key,
        public readonly string  $name,
        public readonly string  $group,
        public readonly array   $domains,
        public readonly string  $imapHost,
        public readonly int     $imapPort,
        public readonly string  $imapEncryption,
        public readonly string  $smtpHost,
        public readonly int     $smtpPort,
        public readonly string  $smtpEncryption,
        public readonly ?string $note = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key:            $key,
            name:           (string) $data['name'],
            group:          (string) ($data['group'] ?? 'Other'),
            domains:        array_map(strtolower(...), $data['domains'] ?? []),
            imapHost:       (string) $data['imap']['host'],
            imapPort:       (int) $data['imap']['port'],
            imapEncryption: (string) $data['imap']['encryption'],
            smtpHost:       (string) $data['smtp']['host'],
            smtpPort:       (int) $data['smtp']['port'],
            smtpEncryption: (string) $data['smtp']['encryption'],
            note:           $data['note'] ?? null,
        );
    }

    public function matchesDomain(string $domain): bool
    {
        return in_array(strtolower($domain), $this->domains, true);
    }

    /**
     * Shape consumed by the imap-preset Stimulus controller.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'name' => $this->name,
            'note' => $this->note,
            'imap' => [
                'host'       => $this->imapHost,
                'port'       => $this->imapPort,
                'encryption' => $this->imapEncryption,
            ],
            'smtp' => [
                'host'       => $this->smtpHost,
                'port'       => $this->smtpPort,
                'encryption' => $this->smtpEncryption,
            ],
            'domains' => $this->domains,
        ];
    }
}
