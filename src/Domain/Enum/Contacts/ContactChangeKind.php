<?php

declare(strict_types=1);

namespace App\Domain\Enum\Contacts;

/**
 * What a contact_change_log row says happened. Trashing reads as destroyed and
 * restoring as created: to a JMAP client a contact in the bin is gone.
 */
enum ContactChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Destroyed = 'destroyed';
}
