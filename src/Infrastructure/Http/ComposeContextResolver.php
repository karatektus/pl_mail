<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\DTO\Mail\ComposeContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Builds the {@see ComposeContext} a compose action asks for, so the action can
 * type-hint it the way it type-hints a Message.
 *
 * The seam this fills: ComposeContext holds no framework types, deliberately —
 * it is Domain, and Domain does not know what a Request is. Something has to
 * read the three query parameters off one, and a resolver is where the
 * framework already offers to do that. The alternative was the private
 * `composeContext($request)` this replaces, called as the first line of eight
 * actions, which is eight chances to forget and one more reason for every
 * action to take a Request it may not otherwise need.
 *
 * Query parameters, not the request body, and that is not an oversight: the
 * window's own POSTs (autosave, send, schedule) put these in the URL precisely
 * because the body is the form's, and a `frame` field inside ComposeType would
 * be a field the form has to ignore.
 */
final readonly class ComposeContextResolver implements ValueResolverInterface
{
    /**
     * @return iterable<ComposeContext>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (ComposeContext::class !== $argument->getType()) {
            return [];
        }

        return [
            ComposeContext::forFrame(
                $request->query->get('frame'),
                $request->query->has('thread') ? $request->query->getInt('thread') : null,
                $request->query->has('reply_to') ? $request->query->getInt('reply_to') : null,
                $request->query->get('mode'),
            ),
        ];
    }
}
