<?php

declare(strict_types=1);

namespace App\Form\Factory;

use App\Form\User\ChangePasswordType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The change-your-password form, built the same way in all three places that
 * need it.
 *
 * Those places are the settings page that shows the card, the endpoint that
 * re-renders it with its errors, and the same endpoint again on success, where
 * a *fresh* one has to replace the filled-in fields. A form whose `action`
 * differed between them would post the settings page's copy to the settings
 * page, which renders the whole application shell inside the card — a failure
 * that looks like a layout bug rather than a wrong URL.
 *
 * Modelled on AliasAddFormFactory, which exists for the same reason: two
 * renderers of one form, and nowhere sensible to put it that is not a service.
 */
final readonly class ChangePasswordFormFactory
{
    public function __construct(
        private FormFactoryInterface  $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function create(): FormInterface
    {
        return $this->forms->create(ChangePasswordType::class, null, [
            'action' => $this->urls->generate('app_settings_password_change'),
        ]);
    }
}
