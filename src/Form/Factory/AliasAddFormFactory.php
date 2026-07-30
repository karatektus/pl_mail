<?php

declare(strict_types=1);

namespace App\Form\Factory;

use App\Entity\Mail\Account;
use App\Form\EmailAliasType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The add-alias form, one per account.
 *
 * Each posts to its own account's route, so a single shared view will not do.
 * Both the settings page and the Turbo stream that replaces the alias list need
 * the same set, which is why this is a service rather than a private method on
 * whichever controller happened to need it first.
 */
final readonly class AliasAddFormFactory
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param iterable<Account> $accounts
     *
     * @return array<int, FormView> keyed by account id
     */
    public function forAccounts(iterable $accounts): array
    {
        $views = [];

        foreach ($accounts as $account) {
            $views[$account->getId()] = $this->forms
                ->create(EmailAliasType::class, null, [
                    'action' => $this->urls->generate('app_alias_add', ['id' => $account->getId()]),
                ])
                ->createView();
        }

        return $views;
    }
}
