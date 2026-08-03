<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\DTO\ParsedSearchQuery;

/**
 * Parses a Gmail-style search string into structured filters + a free-text remainder.
 *
 * Supported operators:
 *   from:alice          → from_address / from_name ILIKE
 *   to:bob              → to_addresses JSON contains
 *   cc:carol            → cc_addresses JSON contains
 *   subject:invoice     → subject ILIKE
 *   label:receipts      → a user label by name
 *   has:attachment      → has_attachments = true
 *   is:unread           → seen_at IS NULL
 *   is:read             → seen_at IS NOT NULL
 *   is:starred          → starred_at IS NOT NULL
 *   in:inbox / sent / drafts / trash / archive / junk / snoozed
 *   after:2024-01-01    → received_at >=
 *   before:2024-12-31   → received_at <
 *
 * Everything else is passed to websearch_to_tsquery as free text.
 *
 * An operator that cannot be honoured — `is:important`, `in:nowhere`, a date
 * that is not a date — falls back to free text rather than being dropped. A
 * dropped operator is a filter the user asked for and did not get, and the
 * result is a page of everything that looks like the search was ignored; as
 * free text it finds little or nothing, which is at least the truth.
 */
final class SearchQueryParser
{
    /**
     * Mailbox names as typed, mapped to the label roles plMail stores. The
     * mapping lives here rather than next to the SQL so an unknown mailbox is
     * caught while the query is still text and can still become free text.
     */
    private const array ROLES = [
        'inbox'    => 'inbox',
        'sent'     => 'sent',
        'drafts'   => 'drafts',
        'draft'    => 'drafts',
        'trash'    => 'trash',
        'deleted'  => 'trash',
        'bin'      => 'trash',
        'junk'     => 'spam',
        'spam'     => 'spam',
        'archive'  => 'archive',
        'archived' => 'archive',
        'snoozed'  => 'snoozed',
    ];

    public function parse(string $raw): ParsedSearchQuery
    {
        $raw      = trim($raw);
        $filters  = new ParsedSearchQuery();
        $remainder = [];

        // Split on spaces but keep quoted strings together
        $tokens = $this->tokenize($raw);

        foreach ($tokens as $token) {
            if (!str_contains($token, ':')) {
                $remainder[] = $token;
                continue;
            }

            [$operator, $value] = explode(':', $token, 2);
            $operator = strtolower(trim($operator));
            $value    = trim(trim($value), '"\'');

            // "from:" with nothing after it, which is every query on its way
            // to being typed. Not a filter — an empty LIKE matches every
            // message, so the half-typed query would answer with the entire
            // mailbox — and not free text either, since searching for the
            // word "from" is not what was meant.
            if ('' === $value) {
                continue;
            }

            $applied = match ($operator) {
                'from'    => (bool) ($filters->from = $value),
                'to'      => (bool) ($filters->to = $value),
                'cc'      => (bool) ($filters->cc = $value),
                'subject' => (bool) ($filters->subject = $value),
                'label'   => (bool) ($filters->label = $value),
                'has'     => $this->applyHas($filters, $value),
                'is'      => $this->applyIs($filters, $value),
                'in'      => $this->applyIn($filters, $value),
                'after'   => null !== ($filters->after = $this->parseDate($value)),
                'before'  => null !== ($filters->before = $this->parseDate($value)),
                default   => false,
            };

            if (false === $applied) {
                $remainder[] = $token;
            }
        }

        $filters->freeText = trim(implode(' ', $remainder));

        return $filters;
    }

    private function tokenize(string $input): array
    {
        $tokens  = [];
        $current = '';
        $inQuote = false;

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $char = $input[$i];

            if ($char === '"') {
                $inQuote  = !$inQuote;
                $current .= $char;
                continue;
            }

            if ($char === ' ' && !$inQuote) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current  = '';
                }
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /** @return bool whether the value was one this understands */
    private function applyHas(ParsedSearchQuery $filters, string $value): bool
    {
        return match (strtolower($value)) {
            'attachment', 'attachments' => $filters->hasAttachment = true,
            default                     => false,
        };
    }

    /** @return bool whether the value was one this understands */
    private function applyIs(ParsedSearchQuery $filters, string $value): bool
    {
        return match (strtolower($value)) {
            'unread'  => $filters->isUnread = true,
            'read'    => $filters->isRead = true,
            'starred' => $filters->isStarred = true,
            default   => false,
        };
    }

    /** @return bool whether the value named a mailbox this install has */
    private function applyIn(ParsedSearchQuery $filters, string $value): bool
    {
        $role = self::ROLES[strtolower($value)] ?? null;

        if (null === $role) {
            return false;
        }

        $filters->mailboxRole = $role;

        return true;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
