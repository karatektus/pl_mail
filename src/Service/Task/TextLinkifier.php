<?php

declare(strict_types=1);

namespace App\Service\Task;

/**
 * Turns the addresses in plain text into links, and escapes everything else.
 *
 * The text is somebody's own, or arrived from a CalDAV server or another client,
 * so it is treated like mail: nothing in it is markup. Tokenised and escaped
 * piece by piece rather than escaped first and searched afterwards, because a
 * search over escaped text finds `&amp;` inside a query string and cuts the
 * address there, and a replace over the original would have to trust it.
 *
 * Only http, https and a bare `www.`, so `javascript:` is never a candidate: the
 * pattern has to name a scheme to find one, and it names two.
 */
final class TextLinkifier
{
    private const string ADDRESS = '~\b(?:https?://|www\.)[^\s<>"\']+~iu';

    /** What ends a sentence after an address and is not part of it. */
    private const string TRAILING = '.,;:!?';

    public function linkify(?string $text): string
    {
        if (null === $text || '' === $text) {
            return '';
        }

        $html   = '';
        $offset = 0;

        preg_match_all(self::ADDRESS, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$address, $start]) {
            $trimmed = $this->withoutTrailing($address);

            $html .= $this->escape(substr($text, $offset, $start - $offset));
            $html .= sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="text-accent underline">%s</a>',
                $this->escape($this->href($trimmed)),
                $this->escape($trimmed),
            );

            $offset = $start + strlen($trimmed);
        }

        return $html . $this->escape(substr($text, $offset));
    }

    private function href(string $address): string
    {
        return 0 === stripos($address, 'www.') ? 'https://' . $address : $address;
    }

    /**
     * Sentence punctuation comes off the end, and so does a closing bracket with
     * no opening one in the address — "(see https://example.org)" — while
     * Wikipedia's "Foo_(bar)" keeps its own.
     */
    private function withoutTrailing(string $address): string
    {
        do {
            $before  = $address;
            $address = rtrim($address, self::TRAILING);

            foreach ([')' => '(', ']' => '[', '}' => '{'] as $closing => $opening) {
                if (true === str_ends_with($address, $closing)
                    && substr_count($address, $closing) > substr_count($address, $opening)) {
                    $address = substr($address, 0, -1);
                }
            }
        } while ($before !== $address);

        return $address;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
