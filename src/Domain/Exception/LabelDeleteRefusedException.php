<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * The mail server was asked to delete a folder or label and said no.
 *
 * Thrown by RemoteLabelRemover around the delete command itself and nothing
 * before it: by then the server has been reached and has answered that the
 * folder is empty, so a refusal is the server's verdict rather than weather —
 * a permission, a protected folder, a server that falls over on the command.
 * Asking again every fifteen seconds for ten minutes changes none of those,
 * and DeleteLabelHandler ends the job at once with the server's own words.
 *
 * What is NOT this: a connection that could not be made, a status query that
 * timed out, a provider asking to slow down. Those happen before the server
 * has said anything about the delete, and are waited out as before.
 */
final class LabelDeleteRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $folder,
        \Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
