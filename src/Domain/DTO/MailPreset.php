<?php

declare(strict_types=1);

namespace App\Domain\DTO;

/**
 * A single provider preset, loaded from config/mail_presets.yaml.
 *
 * Nearly every preset is an IMAP server, with POP3 and JMAP where its server
 * could be confirmed, and the form falls back to IMAP for a protocol it lacks.
 * A JMAP-only preset — plMail itself — has no IMAP and no SMTP, and no host
 * either: every instance has its own, so the preset fills in the rest.
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
        public readonly ?string $imapHost,
        public readonly ?int    $imapPort,
        public readonly ?string $imapEncryption,
        public readonly ?string $smtpHost,
        public readonly ?int    $smtpPort,
        public readonly ?string $smtpEncryption,
        public readonly ?string $note = null,
        public readonly ?string $pop3Host = null,
        public readonly ?int    $pop3Port = null,
        public readonly ?string $pop3Encryption = null,
        public readonly bool    $hasJmap = false,
        /** Null for a server with no one host, such as another plMail. */
        public readonly ?string $jmapHost = null,
        /** Only where the session is not at https://{host}/.well-known/jmap. */
        public readonly ?string $jmapSessionUrl = null,
        /** The password is an API token, sent as Bearer — Fastmail's shape. */
        public readonly bool    $jmapTokenAuth = false,
        /** Shown instead of $note when JMAP is chosen. */
        public readonly ?string $jmapNote = null,
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
            imapHost:       isset($data['imap']) ? (string) $data['imap']['host'] : null,
            imapPort:       isset($data['imap']) ? (int) $data['imap']['port'] : null,
            imapEncryption: isset($data['imap']) ? (string) $data['imap']['encryption'] : null,
            smtpHost:       isset($data['smtp']) ? (string) $data['smtp']['host'] : null,
            smtpPort:       isset($data['smtp']) ? (int) $data['smtp']['port'] : null,
            smtpEncryption: isset($data['smtp']) ? (string) $data['smtp']['encryption'] : null,
            note:           $data['note'] ?? null,
            pop3Host:       isset($data['pop3']) ? (string) $data['pop3']['host'] : null,
            pop3Port:       isset($data['pop3']) ? (int) $data['pop3']['port'] : null,
            pop3Encryption: isset($data['pop3']) ? (string) $data['pop3']['encryption'] : null,
            hasJmap:        isset($data['jmap']),
            jmapHost:       isset($data['jmap']['host']) ? (string) $data['jmap']['host'] : null,
            jmapSessionUrl: $data['jmap']['session_url'] ?? null,
            jmapTokenAuth:  true === ($data['jmap']['token_auth'] ?? false),
            jmapNote:       $data['jmap']['note'] ?? null,
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
            'imap' => null === $this->imapHost ? null : [
                'host'       => $this->imapHost,
                'port'       => $this->imapPort,
                'encryption' => $this->imapEncryption,
            ],
            'pop3' => null === $this->pop3Host ? null : [
                'host'       => $this->pop3Host,
                'port'       => $this->pop3Port,
                'encryption' => $this->pop3Encryption,
            ],
            'jmap' => false === $this->hasJmap ? null : [
                'host'       => $this->jmapHost,
                'sessionUrl' => $this->jmapSessionUrl,
                'tokenAuth'  => $this->jmapTokenAuth,
                'note'       => $this->jmapNote,
            ],
            'smtp' => null === $this->smtpHost ? null : [
                'host'       => $this->smtpHost,
                'port'       => $this->smtpPort,
                'encryption' => $this->smtpEncryption,
            ],
            'domains' => $this->domains,
        ];
    }
}
