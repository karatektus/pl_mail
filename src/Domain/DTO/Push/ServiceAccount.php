<?php

declare(strict_types=1);

namespace App\Domain\DTO\Push;

use App\Domain\Exception\InvalidFirebaseCredentialsException;

/**
 * The three fields of a Google service-account key that FCM actually needs, and
 * the one place a pasted JSON blob becomes them.
 *
 * A service-account key file carries a dozen keys; only `client_email`,
 * `private_key` and `project_id` are ever read — the first two sign and identify
 * the JWT grant, the third addresses `projects/{projectId}/messages:send`.
 * `token_uri` is carried too because the file states it and a key issued for a
 * different Google environment would otherwise be exchanged at the wrong host;
 * it falls back to the public endpoint when absent, which is what every real
 * key says anyway.
 *
 * **Parsing lives here rather than in the form**, because two callers need the
 * same verdict: the admin form, which must refuse a paste and say why, and the
 * sender, which must not attempt a grant against a credential that cannot make
 * one. Two implementations of "is this a service account?" would drift, and the
 * drift would show as an install that saves happily and never delivers.
 *
 * The private key is not validated cryptographically here. openssl_pkey_get_
 * private() is the only thing that can answer that and it is the signer's
 * business; what this refuses is the file that is the wrong file, which is the
 * mistake people actually make — the Firebase console offers the web app config
 * and an OAuth client alongside the service-account key, and all three are JSON.
 */
final readonly class ServiceAccount
{
    /** What a service-account key says it is; anything else is a different file. */
    private const string TYPE = 'service_account';

    /** Where a key with no token_uri of its own is exchanged. */
    public const string DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * The keys whose absence makes the file useless, and what each is for —
     * the message names them, so the wording is the documentation.
     *
     * @var array<string,string>
     */
    private const array REQUIRED = [
        'project_id'   => 'names the Firebase project messages are sent to',
        'client_email' => 'identifies the service account in the token grant',
        'private_key'  => 'signs the token grant',
    ];

    public function __construct(
        public string $projectId,
        public string $clientEmail,
        public string $privateKey,
        public string $tokenUri = self::DEFAULT_TOKEN_URI,
    ) {}

    /**
     * @throws InvalidFirebaseCredentialsException naming what is missing or wrong
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidFirebaseCredentialsException(
                sprintf('That is not valid JSON (%s). Paste the whole key file, braces included.', $exception->getMessage()),
                previous: $exception,
            );
        }

        if (false === is_array($decoded)) {
            throw new InvalidFirebaseCredentialsException('That is valid JSON but not an object. Paste the whole key file, braces included.');
        }

        // Checked before the required keys, and separately: a web app config
        // has none of the three and would otherwise be reported as "missing
        // project_id, client_email, private_key", which reads as a corrupt key
        // rather than as the wrong file.
        $type = $decoded['type'] ?? null;

        if (self::TYPE !== $type) {
            throw new InvalidFirebaseCredentialsException(sprintf(
                'This file says "type": %s. FCM needs a service-account key ("type": "%s") — '
                . 'Firebase console → Project settings → Service accounts → Generate new private key.',
                null === $type ? 'nothing' : json_encode($type),
                self::TYPE,
            ));
        }

        $missing = [];

        foreach (self::REQUIRED as $key => $purpose) {
            $value = $decoded[$key] ?? null;

            if (false === is_string($value) || '' === trim($value)) {
                $missing[] = sprintf('%s (%s)', $key, $purpose);
            }
        }

        if ([] !== $missing) {
            throw new InvalidFirebaseCredentialsException(sprintf(
                'The service-account key is missing %s.',
                implode(', ', $missing),
            ));
        }

        $tokenUri = $decoded['token_uri'] ?? null;

        return new self(
            projectId:   trim((string) $decoded['project_id']),
            clientEmail: trim((string) $decoded['client_email']),
            // NOT trimmed. A PEM block ends with a newline and openssl is
            // content to read one without — but the escaped "\n" sequences the
            // JSON carries are already real newlines by now, and trimming the
            // trailing one is the kind of edit that looks harmless and is.
            privateKey:  (string) $decoded['private_key'],
            tokenUri:    is_string($tokenUri) && '' !== trim($tokenUri) ? trim($tokenUri) : self::DEFAULT_TOKEN_URI,
        );
    }
}
