<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * Turns the free-text remainder of a search into the forms Postgres can match.
 *
 * Why this is not just websearch_to_tsquery
 * -----------------------------------------
 * A tsvector holds LEXEMES, and a lexeme only matches whole. Two consequences
 * bit users hard enough to be reported as one bug:
 *
 *   1. "Testmai" finds nothing, because the mail contains "Testmail" and a
 *      tsquery without `:*` asks for equality, not for a beginning.
 *   2. "wirhub" finds nothing in a body that plainly reads "help.wirhub.de",
 *      because the tokenizer classifies that as ONE `host` token. No amount of
 *      prefix matching fixes this one — "wirhub" is not a prefix of
 *      "help.wirhub.de", it is in the middle of it — so it needs a substring
 *      match, which full-text search structurally cannot offer.
 *
 * So: prefix for (1), a needle for (2), and the original string for everything
 * websearch already got right.
 *
 * Safety
 * ------
 * to_tsquery parses its argument as an expression, so user text cannot be
 * pasted into it — `a:*&!b` typed in the box would otherwise be executable
 * syntax, and an unbalanced quote a 500. Every term is stripped to characters
 * that cannot mean anything to the tsquery parser and then wrapped in single
 * quotes, which is the one context where a lexeme is taken literally. The
 * result is passed as a bound parameter regardless.
 */
final class FreeTextCompiler
{
    /**
     * Below this, a substring needle is both useless and expensive: a trigram
     * index cannot serve a pattern shorter than three characters, so "de"
     * would degrade to a sequential scan over every body in the account, and
     * match half of them anyway.
     */
    private const int MIN_SUBSTRING_LENGTH = 3;

    public function compile(string $freeText): FreeTextQuery
    {
        $freeText = trim($freeText);

        if ('' === $freeText) {
            return new FreeTextQuery('', null, null);
        }

        // Deliberate websearch syntax is honoured exactly as written. Widening
        // it would be the opposite of what quoting and negation are FOR.
        if (true === $this->usesWebsearchSyntax($freeText)) {
            return new FreeTextQuery($freeText, null, null);
        }

        $terms = preg_split('/\s+/', $freeText) ?: [];

        $lexemes = [];

        foreach ($terms as $term) {
            $safe = $this->sanitiseLexeme($term);

            if ('' !== $safe) {
                $lexemes[] = sprintf("'%s':*", $safe);
            }
        }

        $prefix = [] === $lexemes ? null : implode(' & ', $lexemes);

        // One term only. For "invoice acme" the substring pass would have to
        // OR two needles across four columns and would still not answer the
        // question the user asked (both words, same message) any better than
        // the tsquery already does.
        $substring = null;

        if (1 === count($terms) && mb_strlen($freeText) >= self::MIN_SUBSTRING_LENGTH) {
            $substring = $freeText;
        }

        return new FreeTextQuery($freeText, $prefix, $substring);
    }

    /**
     * Escape the wildcards ILIKE would otherwise honour, so a search for "50%"
     * looks for "50%" and not for "50 followed by anything".
     */
    public function likePattern(string $needle): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
    }

    private function usesWebsearchSyntax(string $freeText): bool
    {
        if (true === str_contains($freeText, '"')) {
            return true;
        }

        // `or` is websearch's alternation keyword, in any case.
        if (1 === preg_match('/(^|\s)or(\s|$)/i', $freeText)) {
            return true;
        }

        // A term that starts with `-` is a negation.
        return 1 === preg_match('/(^|\s)-\S/', $freeText);
    }

    /**
     * Keep only what can appear inside a quoted lexeme without ending it.
     *
     * An allowlist rather than a denylist: `&`, `|`, `!`, `(`, `)`, `:`, `*`
     * and the quote itself are all tsquery syntax, and so is whatever the next
     * Postgres release adds. Letters, digits and the punctuation that holds
     * addresses and hostnames together are what a lexeme is made of; the quote
     * cannot survive this, which is what makes the surrounding quoting safe.
     */
    private function sanitiseLexeme(string $term): string
    {
        return preg_replace('/[^\p{L}\p{N}._@+\-\/]+/u', '', $term) ?? '';
    }
}
