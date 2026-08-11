<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a template as a Turbo Stream — the same four lines every controller
 * that patches a fragment needs.
 *
 * Extracted the second time it was wanted. The content type is the whole point:
 * Turbo only applies a stream response when the header says
 * `text/vnd.turbo-stream.html`, and a response that omits it is not an error
 * anywhere — the browser simply navigates to the fragment instead of applying
 * it, which looks like the feature silently not working.
 *
 * A trait rather than a base controller: these controllers already extend
 * Symfony's AbstractController, and there is nothing else a shared parent would
 * be carrying.
 */
trait RendersTurboStreams
{
    /**
     * @param array<string, mixed> $params
     */
    private function renderTurboStream(string $template, array $params = []): Response
    {
        return $this->render($template, $params, new Response(
            headers: ['Content-Type' => 'text/vnd.turbo-stream.html'],
        ));
    }
}
