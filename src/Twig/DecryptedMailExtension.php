<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Crypto\DecryptedMailPolicy;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the "Decrypted mail on this server" card and the page's background
 * decrypter need to know: whether the installation allows it, which of the
 * person's accounts keep decrypted mail, and the model host the models'
 * switch would send it to. See DecryptedMailPolicy.
 */
final class DecryptedMailExtension extends AbstractExtension
{
    public function __construct(
        private readonly DecryptedMailPolicy $policy,
        private readonly AccountRepository   $accounts,
        private readonly Security            $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('decrypted_mail_settings', $this->settings(...)),
            new TwigFunction('keeps_decrypted_mail', $this->keepsAny(...)),
            new TwigFunction('keeps_decrypted', $this->policy->keeps(...)),
        ];
    }

    /**
     * @return array{allowed: bool, model: array{host: ?string, local: bool}, accounts: list<Account>}
     */
    public function settings(): array
    {
        $user = $this->security->getUser();

        return [
            'allowed'  => $this->policy->allowedOnInstall(),
            'model'    => $this->policy->modelHost(),
            'accounts' => $user instanceof User ? array_values(array_filter(
                $this->accounts->findForUserOrdered($user),
                static fn (Account $account): bool => true === $account->isActive,
            )) : [],
        ];
    }

    /**
     * Whether any of the signed-in person's accounts keeps decrypted mail.
     * Read from the person's own settings, which the switch keeps up to date
     * (User::SETTING_KEEPS_DECRYPTED_ANY): this is asked on every page.
     */
    public function keepsAny(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User
            && true === $this->policy->allowedOnInstall()
            && true === $user->getSetting(User::SETTING_KEEPS_DECRYPTED_ANY, false);
    }
}
