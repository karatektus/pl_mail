<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The three shapes one piece of free text has to take to be findable.
 *
 * `websearch` is the string as typed, for websearch_to_tsquery — it is the only
 * one of the three that understands quotes, OR and negation, so it stays the
 * primary match and the other two are only ever ADDED to it.
 *
 * `prefix` is a to_tsquery expression with `:*` on every term, so a half-typed
 * word finds the whole one. Null when the text uses websearch syntax: ORing a
 * prefix match onto `-foo` would hand back exactly the mail the user excluded.
 *
 * `substring` is a bare needle for ILIKE, for the words full-text cannot see at
 * all — the ones inside a token the tokenizer refused to split. Null unless the
 * text is a single term of at least three characters; see SearchStrategy in the
 * repository for why that line is drawn there and what it costs.
 */
final readonly class FreeTextQuery
{
    public function __construct(
        public string  $websearch,
        public ?string $prefix,
        public ?string $substring,
    ) {
    }
}
