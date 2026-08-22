<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\Csp\CspNonce;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()` for the layout's inline scripts.
 *
 * The value has to be the same string the response header carries, so it comes
 * from the shared per-request service rather than being generated here — see
 * CspNonce. A template that mints its own would produce a script the policy
 * naming the other nonce cannot authorise, which is the standard way a
 * nonce-based CSP is got wrong.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce->value(...)),
        ];
    }
}
