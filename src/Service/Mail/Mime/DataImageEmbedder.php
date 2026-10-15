<?php

declare(strict_types=1);

namespace App\Service\Mail\Mime;

use Symfony\Component\Mime\Email;

/**
 * Turns `data:` images in an outgoing body into attached inline parts.
 *
 * A `data:` URI is how an image lives inside a piece of HTML with nothing
 * beside it, which is exactly what a stored signature needs to be — and it is
 * also the one way of carrying an image that mail clients widely refuse to
 * show. Gmail's web client drops the source, Outlook on Windows draws an empty
 * box. A signature whose logo is perfect in the composer would arrive without
 * it at most of the people it was sent to.
 *
 * So on the wire each one becomes what every client does render: a related
 * part with a Content-ID, and a `cid:` reference where the data was. Only on
 * the wire — the stored body keeps its `data:` source, because that is the
 * form the app's own pages can draw.
 *
 * WHAT IS LEFT ALONE
 *
 * Anything that is not a base64 PNG, JPEG, GIF or WebP, and anything that does
 * not decode. An SVG in particular stays as it is: no mail client renders an
 * attached SVG inline either, so moving it would change nothing a recipient
 * sees.
 *
 * The same image used twice is attached once. The part is named for a hash of
 * its bytes, so two references to one logo share a part rather than doubling
 * the size of every mail that carries them.
 */
final readonly class DataImageEmbedder
{
    private const array EXTENSIONS = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Possessive on the payload (`++`): it can run to hundreds of kilobytes,
     * and a pattern that could give characters back would exhaust the
     * backtrack limit on exactly the images this exists for.
     *
     * `&`, `#` and `;` are in the payload's alphabet because the body has been
     * through the sanitiser by the time it is sent, and the sanitiser writes
     * the base64 padding as `&#61;` and a plus as `&#43;`. A pattern holding
     * out for a literal `=` matched every image whose length happened to be a
     * multiple of three and no other.
     */
    private const string PATTERN = '~(<img\b[^>]*?\ssrc\s*=\s*)(["\'])data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=\s&#;]++)\2~i';

    /**
     * The body with its `data:` images pointing at parts now embedded in the
     * email.
     */
    public function embed(Email $email, string $html): string
    {
        if (false === stripos($html, 'data:image/')) {
            return $html;
        }

        $embedded = [];

        $result = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($email, &$embedded): string {
                $type  = strtolower($match[3]);
                $bytes = base64_decode(
                    (string) preg_replace('/\s+/', '', html_entity_decode($match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    true,
                );

                if (false === $bytes || '' === $bytes) {
                    return $match[0];
                }

                $name = sprintf('inline-%s.%s', substr(hash('sha256', $bytes), 0, 16), self::EXTENSIONS[$type]);

                if (false === array_key_exists($name, $embedded)) {
                    $email->embed($bytes, $name, $type);
                    $embedded[$name] = true;
                }

                return $match[1] . $match[2] . 'cid:' . $name . $match[2];
            },
            $html,
        );

        // Null is PCRE giving up. The body goes out as it was: an image some
        // clients will not show is better than a mail with no body.
        return $result ?? $html;
    }
}
