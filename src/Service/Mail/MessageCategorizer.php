<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;

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
        return $this->explain($message, $correspondentEmails)['category'];
    }

    /**
     * The same decision, with the step that made it and the thing it matched
     * on.
     *
     * Recomputed on demand rather than stored: the cascade reads only
     * persisted data — that is the whole design of this class — so explaining
     * a message costs one pass over headers already in memory, and cannot
     * drift from a column written by an older version of these rules.
     *
     * `reason` is a key, not a sentence: the message view translates it.
     * `signal` is the header or domain that decided it, or null where the
     * decision was the absence of any signal.
     *
     * @param array<string,true> $correspondentEmails normalised sender addresses
     *                                                the user has mailed; forces Primary
     *
     * @return array{category: MessageCategory, reason: string, signal: string|null}
     */
    public function explain(Message $message, array $correspondentEmails): array
    {
        $gmailLabelIds = $message->gmailLabelIds;

        // Gmail account: its own classification is authoritative.
        if (null !== $gmailLabelIds) {
            return [
                'category' => MessageCategory::fromGmailLabels($gmailLabelIds),
                'reason'   => 'gmail',
                'signal'   => $this->gmailCategoryLabel($gmailLabelIds),
            ];
        }

        $from = mb_strtolower(trim((string) $message->fromAddress));

        // Correspondence override sits on top of the cascade: if the user has
        // mailed this sender, it belongs in Primary regardless of bulk headers.
        if ('' !== $from && true === isset($correspondentEmails[$from])) {
            return [
                'category' => MessageCategory::Primary,
                'reason'   => 'correspondent',
                'signal'   => $from,
            ];
        }

        $headers = $this->normaliseKeys($message->headers ?? []);

        // Order matters: discussion lists also carry List-Unsubscribe, so
        // Forums must be tested before Promotions.
        $signal = $this->forumSignal($headers);

        if (null !== $signal) {
            return ['category' => MessageCategory::Forums, 'reason' => 'forum', 'signal' => $signal];
        }

        $signal = $this->promotionSignal($headers);

        if (null !== $signal) {
            return ['category' => MessageCategory::Promotions, 'reason' => 'promotion', 'signal' => $signal];
        }

        $signal = $this->socialDomain($from);

        if (null !== $signal) {
            return ['category' => MessageCategory::Social, 'reason' => 'social', 'signal' => $signal];
        }

        $signal = $this->updateSignal($headers, $from);

        if (null !== $signal) {
            return ['category' => MessageCategory::Updates, 'reason' => 'update', 'signal' => $signal];
        }

        return ['category' => MessageCategory::Primary, 'reason' => 'default', 'signal' => null];
    }

    /**
     * Which of Gmail's own category labels was on the message. Null when it
     * carried none, which is how Gmail says Primary.
     *
     * @param list<string> $labelIds
     */
    private function gmailCategoryLabel(array $labelIds): ?string
    {
        foreach ($labelIds as $labelId) {
            if (true === str_starts_with($labelId, 'CATEGORY_')) {
                return $labelId;
            }
        }

        return null;
    }

    /**
     * These return the signal they matched rather than true, so the decision
     * can be explained in the message view without a second set of rules that
     * would be free to disagree with these ones.
     *
     * @param array<string,string> $headers
     */
    private function forumSignal(array $headers): ?string
    {
        foreach (['list-post', 'x-mailman-version', 'x-google-group-id', 'x-discourse-post-id'] as $name) {
            if ('' !== $this->header($headers, $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $headers
     */
    private function promotionSignal(array $headers): ?string
    {
        foreach (['list-unsubscribe', 'feedback-id', 'x-csa-complaints'] as $name) {
            if ('' !== $this->header($headers, $name)) {
                return $name;
            }
        }

        if ('bulk' === mb_strtolower($this->header($headers, 'precedence'))) {
            return 'precedence: bulk';
        }

        return null;
    }

    private function socialDomain(string $fromAddress): ?string
    {
        $atPos = mb_strrpos($fromAddress, '@');

        if (false === $atPos) {
            return null;
        }

        $domain = mb_substr($fromAddress, $atPos + 1);

        foreach (self::SOCIAL_DOMAINS as $social) {
            if ($domain === $social || true === str_ends_with($domain, '.' . $social)) {
                return $social;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $headers
     */
    private function updateSignal(array $headers, string $fromAddress): ?string
    {
        $autoSubmitted = mb_strtolower($this->header($headers, 'auto-submitted'));

        if ('' !== $autoSubmitted && 'no' !== $autoSubmitted) {
            return 'auto-submitted';
        }

        if ('auto_reply' === mb_strtolower($this->header($headers, 'precedence'))) {
            return 'precedence: auto_reply';
        }

        if ('' !== $this->header($headers, 'x-auto-response-suppress')) {
            return 'x-auto-response-suppress';
        }

        $localPart = mb_strstr($fromAddress, '@', true);

        if (false !== $localPart) {
            $localPart = mb_strtolower($localPart);

            foreach (['no-reply', 'noreply', 'do-not-reply', 'donotreply'] as $needle) {
                if (true === str_contains($localPart, $needle)) {
                    return $localPart . '@';
                }
            }
        }

        return null;
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
