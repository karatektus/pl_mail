<?php

declare(strict_types=1);

namespace App\Jmap\State;

/**
 * The JMAP object types whose changes are tracked in the change log.
 * Values match the JMAP type name so they read naturally in queries/logs.
 */
enum JmapObjectType: string
{
    case Mailbox = 'Mailbox';
    case Email = 'Email';
    case Thread = 'Thread';
    case EmailSubmission = 'EmailSubmission';
}
