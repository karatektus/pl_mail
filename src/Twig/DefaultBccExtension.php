<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Mail\DefaultBccProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `default_bcc_map()` — every From option's default Bcc list (#692), keyed by
 * the From selector's own "accountId|address" token — and
 * `compose_default_exceptions()`, the addresses the Compose defaults card
 * lists.
 *
 * A function rather than a controller variable for the reason
 * `signature_map()` is one (see SignatureExtension): compose/_window.html.twig
 * is rendered from several places, some of them `only` includes, and a
 * variable one of them forgot would make the From switch stop swapping the Bcc
 * in exactly that one way of reaching the window.
 */
final class DefaultBccExtension extends AbstractExtension
{
    public function __construct(
        private readonly DefaultBccProvider $defaultBcc,
        private readonly AccountRepository  $accountRepository,
        private readonly Security           $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('default_bcc_map', $this->map(...)),
            new TwigFunction('compose_default_exceptions', self::exceptions(...)),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function map(): array
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return [];
        }

        return $this->defaultBcc->tokenMap($this->accountRepository->findForUserOrdered($user));
    }

    /**
     * The addresses that differ from their account in either compose
     * default — a read-receipt or a Bcc override, read by the key's presence.
     * These, and only these, are listed on the Compose defaults card.
     *
     * @return list<EmailAlias>
     */
    public static function exceptions(Account $account): array
    {
        $exceptions = [];

        foreach ($account->aliases as $alias) {
            $id = (int) $alias->id;

            if (null !== $account->getSetting(Account::readReceiptAliasSetting($id))
                || null !== $account->getSetting(Account::defaultBccAliasSetting($id))) {
                $exceptions[] = $alias;
            }
        }

        return $exceptions;
    }
}
