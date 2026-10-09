<?php

declare(strict_types=1);

namespace App\Domain\Filter;

/**
 * The condition vocabulary a mail rule may use.
 *
 * A near-subset of what EmailFilterCompiler accepts for JMAP. Rules are matched
 * by Postgres through the same compiler, so anything the compiler understands
 * is available — including `text`, which uses the generated tsvector column and
 * so does real stemming.
 *
 * Two things are still excluded, for reasons that are about meaning rather than
 * capability:
 *
 *   inMailbox, inMailboxOtherThan
 *       Addressed by LabelBinding id, which only means something inside JMAP,
 *       where a Mailbox is per-account. Rules use hasLabel/notLabel, which take
 *       a user-scoped Label id — the thing a person actually picks in the UI.
 *
 *   Anything matching on data written later than the id-granting flush
 *       Matching happens in SQL against the stored row, so a condition over a
 *       field a sync path sets after that flush would silently never match.
 *       Nothing in this vocabulary does; see MailRuleEngine for the ordering.
 */
final class FilterVocabulary
{
    /** Conditions taking a non-empty string, matched as a substring. */
    public const array TEXT_CONDITIONS = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'body',
        'filename',
        'text',
        'listId',
    ];

    /**
     * Conditions over the sender that match the whole of something, where
     * `from` matches any part of it.
     *
     *   fromAddress  the sender's address is exactly this one
     *   fromDomain   the part of the sender's address after the `@` is exactly
     *                this — no `@`, and a subdomain is a different domain
     *
     * `from` is a substring over address and display name, which is the right
     * tool for "anything mentioning Acme" and the wrong one for "this sender":
     * `a@x.de` also matches `ba@x.de`, and a display name can be made to
     * contain any address at all. A rule that files a sender under Spam needs
     * to mean that sender and nobody whose address merely ends the same way.
     *
     * Case does not matter for either; nothing else is loosened.
     */
    public const array SENDER_CONDITIONS = [
        'fromAddress',
        'fromDomain',
    ];

    /** Conditions taking an integer. */
    public const array INT_CONDITIONS = [
        'minSize',
        'maxSize',
        'hasLabel',
        'notLabel',
    ];

    /** Conditions taking a UTCDate string. */
    public const array DATE_CONDITIONS = [
        'before',
        'after',
    ];

    /** Conditions taking a boolean. */
    public const array BOOL_CONDITIONS = [
        'hasAttachment',
    ];

    /** Conditions taking one of KEYWORDS. */
    public const array KEYWORD_CONDITIONS = [
        'hasKeyword',
        'notKeyword',
    ];

    public const array KEYWORDS = [
        '$seen',
        '$flagged',
        '$draft',
        '$answered',
    ];

    public const array OPERATORS = ['AND', 'OR', 'NOT'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(
            self::TEXT_CONDITIONS,
            self::SENDER_CONDITIONS,
            self::INT_CONDITIONS,
            self::DATE_CONDITIONS,
            self::BOOL_CONDITIONS,
            self::KEYWORD_CONDITIONS,
        );
    }

    /**
     * Whether a value is one a sender condition can match on.
     *
     * One definition for the validator, which refuses to store anything else,
     * and the compiler, which JMAP reaches without going through the
     * validator. Deliberately loose about what an address looks like — the
     * column holds whatever the mail said — and strict only about the shape
     * the comparison depends on: an address has something either side of an
     * `@`, a domain has no `@` in it, and neither has whitespace.
     */
    public static function isSenderValue(string $condition, mixed $value): bool
    {
        if (false === is_string($value) || '' === $value || 1 === preg_match('/\s/u', $value)) {
            return false;
        }

        $at = strrpos($value, '@');

        if ('fromDomain' === $condition) {
            return false === $at;
        }

        return false !== $at && $at > 0 && $at < strlen($value) - 1;
    }

    public static function supports(string $condition): bool
    {
        return in_array($condition, self::all(), true);
    }
}
