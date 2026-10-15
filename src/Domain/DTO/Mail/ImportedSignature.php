<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * A signature as it will be stored, and what it cost to get it there.
 *
 * The HTML is the cleaned one. The rest is the account of the cleaning, which
 * exists so the settings page can say what happened to markup somebody pasted
 * in good faith instead of quietly showing them less than they wrote.
 */
final readonly class ImportedSignature
{
    /**
     * @param string       $html              sanitised, with the images that could be fetched carried inside it
     * @param list<string> $removedElements   tag names the sanitiser dropped, e.g. `style`, `script`
     * @param list<string> $removedAttributes attribute names it dropped, e.g. `class`, `onclick`
     * @param int          $embeddedImages    remote images now stored in the signature itself
     * @param int          $remoteImages      remote images that could not be, and still point at their URL
     */
    public function __construct(
        public string $html,
        public array  $removedElements = [],
        public array  $removedAttributes = [],
        public int    $embeddedImages = 0,
        public int    $remoteImages = 0,
    ) {
    }

    public function changedAnything(): bool
    {
        return [] !== $this->removedElements || [] !== $this->removedAttributes;
    }

    /**
     * @return array{html: string, removedElements: list<string>, removedAttributes: list<string>, embeddedImages: int, remoteImages: int}
     */
    public function toArray(): array
    {
        return [
            'html'              => $this->html,
            'removedElements'   => $this->removedElements,
            'removedAttributes' => $this->removedAttributes,
            'embeddedImages'    => $this->embeddedImages,
            'remoteImages'      => $this->remoteImages,
        ];
    }
}
