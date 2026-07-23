<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\MessageCategory;
use App\Entity\Message;

/**
 * Resolves a message's inbox category from PERSISTED data only, so the exact
 * same logic runs at sync time and in the "category" backfill task (no
 * re-fetch, no resync).
 *
 * Gmail accounts: trust Gmail's own CATEGORY_* labels (in gmailLabelIds).
 * Everything else: a deterministic cascade over the stored raw headers, plus
 * a from-domain check for Social and a correspondence override that pulls
 * known correspondents back into Primary.
 *
 * Headers are stored raw and unnormalised, so every lookup here goes through
 * header(), which lower-cases the stored keys on demand. Adding a new signal
 * therefore never needs a resync — the header is already on the row.
 */
final class MessageCategorizer
{
    /**
     * Sender domains that map to Social. Kept small and high-signal; extend
     * as needed — a change here is picked up by `app:backfill category`.
     *
     * @var list<string>
     */
    private const array SOCIAL_DOMAINS = [
        'facebookmail.com', 'facebook.com',
        'linkedin.com',
        'x.com', 'twitter.com',
        'instagram.com',
        'reddit.com', 'redditmail.com',
        'youtube.com',
        'pinterest.com',
        'tiktok.com',
        'nextdoor.com',
        'meetup.com',
        'xing.com',
    ];

    /**
     * @param array<string,true> $correspondentEmails normalised sender addresses
     *                                                the user has mailed; forces Primary
     */
    public function categorize(Message $message, array $correspondentEmails): MessageCategory
    {
        $gmailLabelIds = $message->getGmailLabelIds();

        // Gmail account: its own classification is authoritative.
        if (null !== $gmailLabelIds) {
            return MessageCategory::fromGmailLabels($gmailLabelIds);
        }

        $from = mb_strtolower(trim((string) $message->getFromAddress()));

        // Correspondence override sits on top of the cascade: if the user has
        // mailed this sender, it belongs in Primary regardless of bulk headers.
        if ('' !== $from && true === isset($correspondentEmails[$from])) {
            return MessageCategory::Primary;
        }

        $headers = $this->normaliseKeys($message->getHeaders() ?? []);

        // Order matters: discussion lists also carry List-Unsubscribe, so
        // Forums must be tested before Promotions.
        if (true === $this->isForum($headers)) {
            return MessageCategory::Forums;
        }

        if (true === $this->isPromotion($headers)) {
            return MessageCategory::Promotions;
        }

        if (true === $this->isSocial($from)) {
            return MessageCategory::Social;
        }

        if (true === $this->isUpdate($headers, $from)) {
            return MessageCategory::Updates;
        }

        return MessageCategory::Primary;
    }

    /**
     * @param array<string,string> $headers
     */
    private function isForum(array $headers): bool
    {
        if ('' !== $this->header($headers, 'list-post')) {
            return true;
        }

        if ('' !== $this->header($headers, 'x-mailman-version')) {
            return true;
        }

        if ('' !== $this->header($headers, 'x-google-group-id')) {
            return true;
        }

        if ('' !== $this->header($headers, 'x-discourse-post-id')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,string> $headers
     */
    private function isPromotion(array $headers): bool
    {
        if ('' !== $this->header($headers, 'list-unsubscribe')) {
            return true;
        }

        if ('' !== $this->header($headers, 'feedback-id')) {
            return true;
        }

        if ('' !== $this->header($headers, 'x-csa-complaints')) {
            return true;
        }

        if ('bulk' === mb_strtolower($this->header($headers, 'precedence'))) {
            return true;
        }

        return false;
    }

    private function isSocial(string $fromAddress): bool
    {
        $atPos = mb_strrpos($fromAddress, '@');

        if (false === $atPos) {
            return false;
        }

        $domain = mb_substr($fromAddress, $atPos + 1);

        foreach (self::SOCIAL_DOMAINS as $social) {
            if ($domain === $social || true === str_ends_with($domain, '.' . $social)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $headers
     */
    private function isUpdate(array $headers, string $fromAddress): bool
    {
        $autoSubmitted = mb_strtolower($this->header($headers, 'auto-submitted'));

        if ('' !== $autoSubmitted && 'no' !== $autoSubmitted) {
            return true;
        }

        if ('auto_reply' === mb_strtolower($this->header($headers, 'precedence'))) {
            return true;
        }

        if ('' !== $this->header($headers, 'x-auto-response-suppress')) {
            return true;
        }

        $localPart = mb_strstr($fromAddress, '@', true);

        if (false !== $localPart) {
            $localPart = mb_strtolower($localPart);

            if (true === str_contains($localPart, 'no-reply') || true === str_contains($localPart, 'noreply')) {
                return true;
            }

            if (true === str_contains($localPart, 'do-not-reply') || true === str_contains($localPart, 'donotreply')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case every stored header name once per categorisation. Headers are
     * persisted exactly as the server sent them, so casing varies by provider.
     * Repeated headers arrive as arrays — join them so a single string match
     * still sees every occurrence.
     *
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function normaliseKeys(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            if (true === is_array($value)) {
                $value = implode(' ', array_map(static fn($v): string => (string) $v, $value));
            }

            $out[mb_strtolower(trim((string) $name))] = (string) $value;
        }

        return $out;
    }

    /**
     * @param array<string,string> $headers
     */
    private function header(array $headers, string $name): string
    {
        return trim($headers[$name] ?? '');
    }
}
