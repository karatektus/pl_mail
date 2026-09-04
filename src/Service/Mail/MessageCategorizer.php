<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Embeddable\CategorySorting;
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
    public function categorize(
        Message $message,
        array $correspondentEmails,
        /**
         * Whose preference decides, or null for the shipped behaviour.
         *
         * Null rather than a default object, so a caller that genuinely has no
         * user — the Gmail enrichment path re-deriving a category for a row it
         * just wrote — is answered the way it always was rather than silently
         * given somebody else's settings.
         */
        ?CategorySorting $sorting = null,
    ): MessageCategory {
        return $this->explain(
            $message,
            $correspondentEmails,
            true === $sorting?->overrideProvider,
            true === $sorting?->ignoresAi(),
            true === $sorting?->assistantFirst(),
        )['category'];
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
     * @param bool               $ignoreAi
     *     Answer without the model's opinion, as though it had never been
     *     asked. The tie-break at the bottom is the only place a stored
     *     verdict is read, so this is what "the rules alone" means — and it is
     *     what the message details panel puts beside the model's answer, so the
     *     two can be compared on the same mail rather than argued about.
     * @param bool               $ignoreProviderLabels
     *     Answer from THIS application's rules alone, as though the account
     *     were not a Gmail one.
     *
     *     Gmail's own CATEGORY_* labels are authoritative when they are there,
     *     which means the local cascade below never runs on a Gmail mailbox and
     *     nobody can see what it would have decided. That matters on the day
     *     the labels stop arriving — an account moved off Gmailify, a mailbox
     *     migrated to IMAP — because the local rules take over instantly and
     *     silently, having never been checked against real mail. This is how
     *     the message view offers both answers side by side, so the difference
     *     is something to look at rather than something to discover later.
     *
     * @return array{category: MessageCategory, reason: string, signal: string|null}
     */
    public function explain(
        Message $message,
        array $correspondentEmails,
        bool $ignoreProviderLabels = false,
        bool $ignoreAi = false,
        bool $aiFirst = false,
    ): array {
        $gmailLabelIds = $message->gmailLabelIds;

        // Gmail account: its own classification is authoritative.
        if (null !== $gmailLabelIds && false === $ignoreProviderLabels) {
            return [
                'category' => MessageCategory::fromGmailLabels($gmailLabelIds),
                'reason'   => 'gmail',
                'signal'   => $this->gmailCategoryLabel($gmailLabelIds),
            ];
        }

        $from = mb_strtolower(trim((string) $message->fromAddress));

        $headers = $this->normaliseKeys($message->headers ?? []);

        // Order matters: discussion lists also carry List-Unsubscribe, so
        // Forums must be tested before Promotions. Both are computed here
        // rather than in the cascade below because the correspondence override
        // now has to know whether either matched — see immediately below.
        $forum     = $this->forumSignal($headers);
        $promotion = null !== $forum ? null : $this->promotionSignal($headers);

        // THE CORRESPONDENCE OVERRIDE, AND IT NO LONGER BEATS A MAILING.
        //
        // It says: you have written to this sender, so this belongs in Primary.
        // That is the right rescue for a person whose mail happens to carry a
        // bulk-ish header, and it was doing something else entirely — writing
        // once to a shop's info@ address, years ago, about a broken part, pinned
        // every anniversary sale that shop ever sent into Primary for ever.
        //
        // The distinction it was missing is that a person you correspond with
        // does not send you mail with an unsubscribe link in it. Their
        // marketing platform does, from the same address, and the address is
        // all this rule could see.
        //
        // So it now rescues mail that is not a MAILING — which is the case it
        // was written for. A message carrying a list header, a list id or an
        // explicit bulk precedence is a mailing whoever it is from, and the
        // cascade below files it as one. Everything weaker than that — a
        // feedback-id, an automation marker, an unfamiliar sending domain —
        // still loses to somebody the reader has written to, which is the whole
        // point of the rule.
        if ('' !== $from && true === isset($correspondentEmails[$from])
            && null === $forum && null === $promotion) {
            return [
                'category' => MessageCategory::Primary,
                'reason'   => 'correspondent',
                'signal'   => $from,
            ];
        }

        // THE ASSISTANT, WHEN IT HAS BEEN CHOSEN TO DECIDE. Below the
        // correspondence override and above everything else, and both halves
        // of that placement are deliberate.
        //
        // Above the cascade, because that is what choosing it means. Read only
        // as a tie-break — which is where the verdict sat before this was a
        // setting — a newsletter carrying List-Unsubscribe is Promotions
        // whatever the model made of it, and nothing on screen says so.
        //
        // Below the correspondent override, because that rule is not a
        // classification at all: it says this is somebody you have written to,
        // which is a fact about the reader rather than about the mail. Putting
        // a model above it would let mail from a person they correspond with
        // land in Promotions on a bad guess, and that is the one mistake in
        // this whole cascade anybody would actually notice.
        if (true === $aiFirst && false === $ignoreAi && null !== $message->aiCategory) {
            return [
                'category' => $message->aiCategory,
                'reason'   => 'ai',
                'signal'   => null,
            ];
        }

        if (null !== $forum) {
            return ['category' => MessageCategory::Forums, 'reason' => 'forum', 'signal' => $forum];
        }

        $signal = $promotion;

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

        // ── The tie-breaker, and only here ────────────────────────────────
        // Every rule above has now declined to recognise this message, and the
        // honest answer at this point is "we do not know, so Primary". That is
        // the ONE place a model's opinion can help without doing harm.
        //
        // Above this line it could only ever overrule something that actually
        // matched a header, which would make a tab's contents depend on which
        // model happened to be installed — and would silently move mail a
        // documented rule had placed. Below it there is nothing left to decide.
        //
        // No call is made from here. This reads a verdict that was stored
        // asynchronously if one exists; a categoriser that reached out to
        // another machine would put an HTTP round trip inside every list
        // render, every ingest and every explain() the details panel performs.
        if (false === $ignoreAi && null !== $message->aiCategory) {
            return [
                'category' => $message->aiCategory,
                'reason'   => 'ai',
                'signal'   => null,
            ];
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
        // feedback-id IS NOT ON THIS LIST ANY MORE, and its removal is a fix
        // rather than a tidy-up.
        //
        // It is a feedback-loop identifier an ESP stamps on everything it
        // sends, transactional mail included, so on its own it says the mail
        // went through a sending service and nothing about what the mail is
        // for. It filed a recruiter's request for missing application
        // documents — a one-to-one letter from a named person, expecting a
        // reply — as Promotions, on the strength of the HR system's mail
        // provider.
        //
        // The two that stay both mean a MAILING: an unsubscribe mechanism, and
        // the German CSA's complaint address. Marketing is legally obliged to
        // offer the first, which is what makes it the reliable signal and what
        // makes its absence beside a feedback-id informative in its own right.
        foreach (['list-unsubscribe', 'x-csa-complaints'] as $name) {
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
