<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ImportedSignature;
use Dom\HTMLDocument;

/**
 * A signature written as HTML, made into one plMail will store.
 *
 * Two things happen to it, and the caller is told about both.
 *
 * IT IS SANITISED, through the same allow-list as every other signature
 * (SignatureProvider::sanitize()). For a signature typed into the editor that
 * removes nothing anyone would notice. For one pasted as source out of a
 * generator or another mail client it routinely does — a `<style>` block, the
 * `class` attributes that pointed into it — and a signature that silently
 * renders differently from what was pasted reads as a bug. So the difference
 * is reported: which elements and which attributes were in what came in and
 * are not in what goes out.
 *
 * ITS REMOTE IMAGES ARE BROUGHT INSIDE. An `<img src="https://…">` is fetched
 * once and stored in the signature as a `data:` source. That is what makes the
 * logo show at all in plMail's own pages, whose content policy loads images
 * from nowhere else, and it means the recipient is sent the image rather than
 * a request to somebody's web server — no "load remote content?" prompt, no
 * broken logo the day the host goes away. MessageSendService turns the `data:`
 * source into an attached inline part when the mail is built, since a data URI
 * is not something mail clients display (see DataImageEmbedder).
 *
 * WHAT STAYS REMOTE
 *
 * An image that cannot be fetched, is not a PNG, JPEG, GIF or WebP, or is
 * larger than MAX_IMAGE_BYTES keeps its URL and is counted. The cap is there
 * because a signature is not paid for once: it sits in every draft, is handed
 * to every compose window, and goes out with every mail.
 *
 * The fetch goes through ImageProxyFetcher and so under its rules — public
 * addresses only, bounded size and time, redirects re-checked hop by hop.
 */
final readonly class SignatureImporter
{
    /** One image. A logo is a few kilobytes; a photograph is not a signature. */
    private const int MAX_IMAGE_BYTES = 256 * 1024;

    /** Everything embedded in one signature. */
    private const int MAX_TOTAL_BYTES = 768 * 1024;

    private const array EMBEDDABLE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * The scaffolding of a whole document. Pasting a full page as a signature
     * loses these by definition and nobody misses them, so they are not
     * reported as something that was taken away.
     */
    private const array STRUCTURAL = ['html', 'head', 'body', 'title', 'meta'];

    /**
     * Attributes the sanitiser may rewrite or add but never meaningfully
     * removes: `src` changes when an image is embedded, and `target` and `rel`
     * are forced onto every link.
     */
    private const array UNREPORTED_ATTRIBUTES = ['src', 'target', 'rel'];

    public function __construct(
        private SignatureProvider $signatures,
        private ImageProxyFetcher $images,
    ) {
    }

    public function import(?string $html): ImportedSignature
    {
        $html  = (string) $html;
        $clean = $this->signatures->sanitize($html);

        [$removedElements, $removedAttributes] = $this->removed($html, $clean);
        [$clean, $embedded, $remote]           = $this->embedImages($clean);

        return new ImportedSignature($clean, $removedElements, $removedAttributes, $embedded, $remote);
    }

    /**
     * Element and attribute names present before sanitising and absent — or
     * fewer — after it.
     *
     * Counted rather than compared as text. The sanitiser re-serialises
     * everything it keeps, so the two strings differ in quoting and entity
     * spelling even when nothing was dropped, and a diff of them would cry
     * wolf on every save.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function removed(string $before, string $after): array
    {
        [$elementsBefore, $attributesBefore] = $this->census($before);
        [$elementsAfter, $attributesAfter]   = $this->census($after);

        return [
            $this->fewer($elementsBefore, $elementsAfter, self::STRUCTURAL),
            $this->fewer($attributesBefore, $attributesAfter, self::UNREPORTED_ATTRIBUTES),
        ];
    }

    /**
     * @return array{0: array<string,int>, 1: array<string,int>} element counts and attribute counts, by name
     */
    private function census(string $html): array
    {
        $elements   = [];
        $attributes = [];

        if ('' === trim($html)) {
            return [$elements, $attributes];
        }

        // The HTML5 parser, the one a browser would use on the same string, so
        // the count is of what the markup means rather than of how it is
        // spelled.
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
        );

        foreach ($document->getElementsByTagName('*') as $element) {
            $name            = strtolower($element->localName);
            $elements[$name] = ($elements[$name] ?? 0) + 1;

            foreach ($element->attributes as $attribute) {
                $key              = strtolower($attribute->name);
                $attributes[$key] = ($attributes[$key] ?? 0) + 1;
            }
        }

        return [$elements, $attributes];
    }

    /**
     * @param array<string,int> $before
     * @param array<string,int> $after
     * @param list<string>      $ignored
     *
     * @return list<string>
     */
    private function fewer(array $before, array $after, array $ignored): array
    {
        $names = [];

        foreach ($before as $name => $count) {
            if (false === in_array($name, $ignored, true) && $count > ($after[$name] ?? 0)) {
                $names[] = (string) $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return array{0: string, 1: int, 2: int} the HTML, images embedded, images left remote
     */
    private function embedImages(string $html): array
    {
        $embedded = 0;
        $remote   = 0;
        $total    = 0;

        // After the sanitiser, which is why the pattern can be this plain: it
        // writes every attribute double-quoted and entity-encoded.
        $result = preg_replace_callback(
            '~(<img\b[^>]*?\ssrc=")(https?:[^"]+)(")~i',
            function (array $match) use (&$embedded, &$remote, &$total): string {
                $data = $this->dataUri(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $total);

                if (null === $data) {
                    ++$remote;

                    return $match[0];
                }

                ++$embedded;

                return $match[1] . $data . $match[3];
            },
            $html,
        );

        return [$result ?? $html, $embedded, $remote];
    }

    private function dataUri(string $url, int &$total): ?string
    {
        $fetched = $this->images->fetch($url);

        if (null === $fetched) {
            return null;
        }

        $type = strtolower(trim(explode(';', $fetched['contentType'])[0]));
        $size = (int) @filesize($fetched['path']);

        if (
            false === in_array($type, self::EMBEDDABLE, true)
            || $size <= 0
            || $size > self::MAX_IMAGE_BYTES
            || $total + $size > self::MAX_TOTAL_BYTES
        ) {
            return null;
        }

        $bytes = @file_get_contents($fetched['path']);

        if (false === $bytes || '' === $bytes) {
            return null;
        }

        $total += $size;

        return sprintf('data:%s;base64,%s', $type, base64_encode($bytes));
    }
}
