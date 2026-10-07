<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Label\Label;

/**
 * One "Move to", worked out: what a conversation gains and what it gives up.
 *
 * The answer to a question only the server may answer. The client says which
 * list the person was looking at and where they pointed; which label that
 * takes OFF is derived from those two, by MoveToService::plan(), and arrives
 * here. A client-supplied "remove these" list would be a way of stripping any
 * label off any conversation through a route whose button says "move".
 *
 * $leaving is nullable because not every move takes something away: filed from
 * a search result, a conversation that was never in the inbox simply gains the
 * target. And it is only ever removed from the messages that carry it — a
 * reply sitting in Sent is part of the thread and was never in the Inbox.
 */
final readonly class LabelMove
{
    public function __construct(
        public Label  $target,
        public ?Label $leaving,
        /**
         * The target is the list the person is already looking at. Nothing is
         * written and nothing is announced: the picker hides that entry, and
         * this is the other half of that for a caller that posts it anyway.
         */
        public bool   $isNoop = false,
    ) {
    }
}
