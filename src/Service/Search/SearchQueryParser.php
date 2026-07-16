<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\DTO\ParsedSearchQuery;

/**
 * Parses a Gmail-style search string into structured filters + a free-text remainder.
 *
 * Supported operators:
 *   from:alice          → fromAddress ILIKE
 *   to:bob              → toAddresses JSON contains
 *   subject:invoice     → subject ILIKE
 *   has:attachment      → has_attachments = true
 *   is:unread           → seen_at IS NULL
 *   is:read             → seen_at IS NOT NULL
 *   is:starred          → starred_at IS NOT NULL
 *   in:inbox / in:sent / in:drafts / in:trash / in:archive / in:junk
 *   after:2024-01-01    → received_at >=
 *   before:2024-12-31   → received_at <
 *
 * Everything else is passed to websearch_to_tsquery as free text.
 */
final class SearchQueryParser
{
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
            $value    = trim($value, '"\'');

            match ($operator) {
                'from'    => $filters->from = $value,
                'to'      => $filters->to = $value,
                'subject' => $filters->subject = $value,
                'has'     => $this->applyHas($filters, $value),
                'is'      => $this->applyIs($filters, $value),
                'in'      => $filters->mailboxRole = strtolower($value),
                'after'   => $filters->after = $this->parseDate($value),
                'before'  => $filters->before = $this->parseDate($value),
                default   => $remainder[] = $token, // unknown operator → treat as text
            };
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

    private function applyHas(ParsedSearchQuery $filters, string $value): void
    {
        match (strtolower($value)) {
            'attachment', 'attachments' => $filters->hasAttachment = true,
            default                     => null,
        };
    }

    private function applyIs(ParsedSearchQuery $filters, string $value): void
    {
        match (strtolower($value)) {
            'unread'   => $filters->isUnread = true,
            'read'     => $filters->isRead = true,
            'starred'  => $filters->isStarred = true,
            default    => null,
        };
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
