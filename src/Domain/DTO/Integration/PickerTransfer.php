<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * The outcome of pulling a picker selection into a draft.
 *
 * Links and errors are both lists rather than a single result because one
 * submission can do both: a selection of five files can copy three, link one
 * and fail on the fifth, and reporting only the first outcome would leave the
 * user guessing which of their files made it.
 */
final readonly class PickerTransfer
{
    /**
     * @param list<array{name:string,url:string}> $links    shared URLs for the body
     * @param list<string>                        $errors   file names or failure messages
     * @param int                                 $attached files copied into the draft
     */
    public function __construct(
        public array $links,
        public array $errors,
        public int   $attached,
    ) {
    }
}
