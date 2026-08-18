<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\Mail\AccountRepository;
use App\Service\Mail\SignatureProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `signature_map()` — every From option's signature, keyed by the From
 * selector's own "accountId|address" token.
 *
 * A function rather than a controller variable, for the reason
 * `read_receipt_decision` is one: compose/_window.html.twig is included from
 * three places (the two routes that render it, plus _dock_undo.stream and
 * _inline_undo, both of which include it `only`), and threading one more
 * variable through all of them means the next caller has to remember to. Here
 * forgetting would mean the From switch quietly stops changing the signature
 * in exactly one of the ways a user can reach the window.
 */
final class SignatureExtension extends AbstractExtension
{
    public function __construct(
        private readonly SignatureProvider $signatures,
        private readonly AccountRepository $accountRepository,
        private readonly Security          $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('signature_map', $this->map(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function map(): array
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return [];
        }

        return $this->signatures->tokenMap($this->accountRepository->findForUserOrdered($user));
    }
}
