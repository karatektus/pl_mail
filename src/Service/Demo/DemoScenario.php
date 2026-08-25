<?php

declare(strict_types=1);

namespace App\Service\Demo;

/**
 * One scripted piece of mail the demo can deliver on demand.
 *
 * A value object rather than a row, because these are content, not data: they
 * ship with the code, they are identical on every demo instance, and nothing a
 * visitor does should be able to edit them. Keeping them out of the database
 * also means the reaper can delete a demo user without worrying that it is
 * deleting the script along with them.
 *
 * `label` names a custom label the message is filed under in addition to the
 * inbox, and is what makes a delivery visibly do more than append a row — the
 * sidebar count moves. `attachment` is a filename/contents pair written to real
 * blob storage, so the chip a visitor clicks downloads a real file rather than
 * 404ing.
 */
final readonly class DemoScenario
{
    /**
     * @param array{string, string}|null $attachment filename and contents
     */
    public function __construct(
        public string  $key,
        public string  $subject,
        public string  $fromName,
        public string  $fromAddress,
        public string  $bodyText,
        public ?string $bodyHtml = null,
        public ?string $label = null,
        public ?array  $attachment = null,
    ) {
    }
}
