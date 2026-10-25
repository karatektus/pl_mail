<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * An uploaded SVG cut down to a picture and nothing else (#48).
 *
 * A custom logo is served publicly (a browser fetches a tab icon and a
 * manifest's icons without credentials) and drawn by every page its owner
 * opens, so the file is treated as hostile: an SVG is a document, and a
 * document can carry script, event handlers, links, embedded HTML and
 * references to anything on the network.
 *
 * WHY AN ALLOWLIST, WRITTEN HERE
 * ──────────────────────────────
 * Everything not named below is removed: elements, attributes, and every
 * reference that does not point inside the file itself. A denylist of the
 * known-dangerous constructs is the usual alternative and the usual source of
 * bypasses, because SVG keeps growing ways to run or fetch things. The
 * vocabulary a logo needs is small and stable: shapes, paths, groups,
 * gradients, clips, masks, a few filters, and the `<style>` block that
 * Illustrator and Inkscape write. symfony/html-sanitizer is already here, but
 * it sanitises HTML and has no SVG vocabulary.
 *
 * It is the first of two locks. The routes serving the file also send a
 * Content-Security-Policy that runs nothing and loads nothing (see
 * BrandingController::customSvg()), so a construct this misses still could
 * not do anything when the file is opened on its own.
 *
 * A DOCTYPE is refused outright rather than parsed: it is where entity
 * expansion (the "billion laughs") and external entities live, and no drawing
 * program needs one.
 */
final class SvgSanitizer
{
    /** Far above any logo, far below anything worth parsing for long. */
    public const int MAX_BYTES = 256 * 1024;

    private const string SVG_NS = 'http://www.w3.org/2000/svg';
    private const string XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** @var list<string> */
    private const array ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask',
        // Filters, without feImage: it is the one primitive that loads a picture.
        'filter', 'feGaussianBlur', 'feOffset', 'feFlood', 'feComposite', 'feMerge', 'feMergeNode',
        'feBlend', 'feColorMatrix', 'feDropShadow', 'feMorphology',
    ];

    /** @var list<string> */
    private const array ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'viewBox', 'preserveAspectRatio', 'width', 'height',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr',
        'd', 'points', 'pathLength',
        'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask', 'filter', 'opacity',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'vector-effect',
        'stop-color', 'stop-opacity', 'offset', 'color', 'display', 'visibility', 'overflow',
        'paint-order', 'mix-blend-mode', 'isolation', 'shape-rendering',
        'gradientUnits', 'gradientTransform', 'spreadMethod',
        'patternUnits', 'patternContentUnits', 'patternTransform',
        'clipPathUnits', 'maskUnits', 'maskContentUnits',
        'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'stdDeviation', 'dx', 'dy',
        'operator', 'k1', 'k2', 'k3', 'k4', 'mode', 'type', 'values', 'radius',
        'flood-color', 'flood-opacity', 'color-interpolation-filters',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'letter-spacing', 'href',
    ];

    /**
     * The cleaned document, or null when $svg is not something a logo can be
     * made of: too big, not XML, not an `<svg>` root, or nothing that says how
     * big it is (no viewBox, and no width and height to make one from).
     */
    public function sanitize(string $svg): ?string
    {
        if ('' === $svg || strlen($svg) > self::MAX_BYTES || 1 === preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            return null;
        }

        $document = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: nothing in the file may make the parser fetch.
        // Deliberately not LIBXML_NOENT, which would expand entities.
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (false === $loaded || null === $root || 'svg' !== $root->localName || self::SVG_NS !== $root->namespaceURI) {
            return null;
        }

        $this->clean($root);

        if (false === $this->ensureViewBox($root)) {
            return null;
        }

        $clean = new \DOMDocument();
        $clean->appendChild($clean->importNode($root, true));

        $out = $clean->saveXML($clean->documentElement);

        return false === $out ? null : $out;
    }

    private function clean(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if (false === $this->keepAttribute($attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (self::SVG_NS === $child->namespaceURI && true === in_array($child->localName, self::ELEMENTS, true)) {
                    $this->clean($child);

                    if ('style' === $child->localName && false === $this->safeCss((string) $child->textContent)) {
                        $element->removeChild($child);
                    }

                    continue;
                }

                $element->removeChild($child);

                continue;
            }

            // Text and CDATA are kept (a <style> block, a <text> label);
            // comments and processing instructions go.
            if (false === $child instanceof \DOMText) {
                $element->removeChild($child);
            }
        }
    }

    private function keepAttribute(\DOMAttr $attribute): bool
    {
        $name  = $attribute->localName;
        $value = $attribute->value;

        // xlink:href and plain href alike: only a reference into this file.
        if ('href' === $name) {
            return (null === $attribute->namespaceURI || self::XLINK_NS === $attribute->namespaceURI)
                && 1 === preg_match('/^#[\w.:-]+$/', trim($value));
        }

        if (null !== $attribute->namespaceURI && '' !== $attribute->namespaceURI) {
            // xml:space is harmless; every other namespaced attribute (an
            // editor's own sodipodi:/inkscape: bookkeeping included) goes.
            return 'http://www.w3.org/XML/1998/namespace' === $attribute->namespaceURI && 'space' === $name;
        }

        if (false === in_array($name, self::ATTRIBUTES, true)) {
            return false;
        }

        return 'style' === $name ? $this->safeCss($value) : $this->safeReference($value);
    }

    /**
     * A value that names nothing outside the file: `url(#id)` is how a fill
     * points at its gradient, and any other url() is a fetch.
     */
    private function safeReference(string $value): bool
    {
        if (1 === preg_match('/(javascript|data|vbscript)\s*:/i', $value)) {
            return false;
        }

        return 0 === preg_match_all('/url\s*\(/i', $value) - preg_match_all('/url\(\s*[\'"]?#[\w.:-]+[\'"]?\s*\)/i', $value);
    }

    /**
     * CSS from a style attribute or a `<style>` block: the same rule as any
     * other value, and nothing that could reach outside it. Backslashes are
     * refused rather than decoded, because CSS escapes are how `url` is
     * spelled so that a check like the one above does not see it.
     */
    private function safeCss(string $css): bool
    {
        if (true === str_contains($css, '\\') || true === str_contains($css, '<') || 1 === preg_match('/@import|expression\s*\(|behavio(u)?r\s*:/i', $css)) {
            return false;
        }

        return $this->safeReference($css);
    }

    /**
     * Every consumer scales the logo — a 16px tab, a 36px bar, a 512px PNG —
     * and only a viewBox says what to scale. A drawing with a width and a
     * height but no viewBox is given the one they imply.
     */
    private function ensureViewBox(\DOMElement $root): bool
    {
        if (1 === preg_match('/^\s*-?[\d.]+([\s,]+-?[\d.]+){3}\s*$/', $root->getAttribute('viewBox'))) {
            return true;
        }

        $width  = $this->length($root->getAttribute('width'));
        $height = $this->length($root->getAttribute('height'));

        if (null === $width || null === $height) {
            return false;
        }

        $root->setAttribute('viewBox', sprintf('0 0 %s %s', $width, $height));

        return true;
    }

    /** A plain or px length, as a number; null for anything relative. */
    private function length(string $value): ?string
    {
        return 1 === preg_match('/^\s*([\d.]+)\s*(px)?\s*$/', $value, $match) && (float) $match[1] > 0 ? $match[1] : null;
    }
}
