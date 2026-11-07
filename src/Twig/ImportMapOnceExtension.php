<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\Csp\CspNonce;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `importmap_once('app')`: the layout's import map, rendered once per worker
 * and given each request's CSP nonce.
 *
 * The import map is the same 42 KB on every page and every list click until
 * the next deploy — 188 modules, each looked up in the compiled manifest and
 * turned into a versioned URL — and making it was 7.4 ms of every response
 * with debug off, about 30 ms on the NAS plMail runs on. The one thing that
 * differs between two renders is the nonce on its three script tags, so it is
 * rendered with a placeholder, kept, and the request's nonce put in its place.
 *
 * Kept in this object, which outlives requests in FrankenPHP's worker mode and
 * is gone when a deploy restarts the workers — the only time the map changes.
 * Keyed by entry point and base path, the two inputs a render reads apart from
 * the manifest.
 *
 * Not with debug on: there the asset mapper serves files as they are edited,
 * and a map kept from the first request would point at the old versions.
 *
 * One thing the stock function does that this skips after the first render:
 * it adds a Link header for each stylesheet or JSON file marked for preload.
 * The map preloads neither today, and the header was never sent; a map that
 * starts preloading a stylesheet should take that into account here.
 */
final class ImportMapOnceExtension extends AbstractExtension
{
    /** A string no nonce can contain: a nonce is base64. */
    private const string PLACEHOLDER = '%importmap-nonce%';

    /** @var array<string, string> entry point and base path => the map with the placeholder */
    private array $rendered = [];

    public function __construct(
        #[Autowire(service: 'asset_mapper.importmap.renderer')]
        private readonly ImportMapRenderer $renderer,
        private readonly CspNonce $nonce,
        private readonly RequestStack $requests,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('importmap_once', $this->render(...), ['is_safe' => ['html']]),
        ];
    }

    public function render(string $entryPoint): string
    {
        $nonce = $this->nonce->value();

        if (true === $this->debug) {
            return $this->renderer->render($entryPoint, ['nonce' => $nonce]);
        }

        $key = $entryPoint . "\n" . ($this->requests->getCurrentRequest()?->getBasePath() ?? '');

        $this->rendered[$key] ??= $this->renderer->render($entryPoint, ['nonce' => self::PLACEHOLDER]);

        return str_replace(self::PLACEHOLDER, htmlspecialchars($nonce, ENT_QUOTES), $this->rendered[$key]);
    }
}
