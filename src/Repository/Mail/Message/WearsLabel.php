<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Message;

/**
 * "The email `m` wears a label that …", as DQL for a WHERE clause.
 *
 * Asked through the email's own labels rather than as a sub-query over label
 * with `label MEMBER OF m.labels` in it. Doctrine writes MEMBER OF as a
 * message_label lookup nested inside that sub-query, which Postgres cannot
 * flatten: it hashes the whole of message_label for each such condition, and a
 * mail page asks eight counts with one or two of them apiece. This form is a
 * plain join, which Postgres can turn into a semi-join or anti-join and start
 * from the label.
 */
final class WearsLabel
{
    /**
     * @param string $alias     the label's name in $condition; it names the
     *                          sub-query's email too, so unique in the query
     * @param string $condition DQL on that label, such as `binned.role = :role`
     */
    public static function where(string $alias, string $condition): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$sEmail JOIN %2$sEmail.labels %2$s WHERE %2$sEmail = m AND %3$s)',
            Message::class,
            $alias,
            $condition,
        );
    }
}
