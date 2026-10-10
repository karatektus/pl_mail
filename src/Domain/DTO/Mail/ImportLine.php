<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;

/**
 * How far one account's first import has got, for the person it belongs to.
 *
 * Not a BackgroundJob, although it is shown in the same indicator. A job row
 * is a record of something somebody started, and it is reaped as abandoned
 * when it stops moving for a quarter of an hour — which an import does as a
 * matter of course, between Gmail listings or while a provider is throttling.
 * So there is no row to go stale: this is worked out, each time it is asked
 * for, from the account and its folders, which are what the import itself
 * reads to know where it is. See ImportProgress.
 */
final readonly class ImportLine
{
    /**
     * @param int|null     $total   how many messages the import set out to
     *                              fetch, or null while that is not known yet
     * @param Mailbox|null $folder  the folder being read, where the provider
     *                              has folders
     * @param Label|null   $label   the same, for a provider whose folders are
     *                              labels and have no Mailbox row
     * @param bool         $waiting nothing has moved for a while: between two
     *                              listings, or because something is wrong —
     *                              the account's own health says which
     */
    public function __construct(
        public Account  $account,
        public int      $done,
        public ?int     $total,
        public ?Mailbox $folder,
        public bool     $waiting,
        public ?Label   $label = null,
    ) {}

    /** Whole percent, floored and never past 100; 0 until there is a total. */
    public function percent(): int
    {
        if (null === $this->total || 0 >= $this->total) {
            return 0;
        }

        return (int) floor(min(100, ($this->done / $this->total) * 100));
    }
}
