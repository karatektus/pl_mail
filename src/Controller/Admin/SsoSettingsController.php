<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Admin\OidcConfigType;
use App\Service\Oidc\OidcConfigWriter;
use App\Service\Oidc\OidcSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → SSO: the OpenID Connect provider people sign in to plMail through.
 *
 * A section of its own rather than a row under Integrations. Those are
 * services people attach files from once they are in; this decides how they
 * get in, and an administrator looking for it does not think of it as an
 * integration.
 *
 * Shaped like Admin → Push, for the reasons PushSettingsController gives: one
 * action for GET and POST so a refused submission re-renders with what was
 * typed, and a Turbo Frame because this one form IS the section.
 */
#[Route('/admin/sso', name: 'app_admin_sso_')]
#[IsGranted('ROLE_ADMIN')]
final class SsoSettingsController extends AbstractController
{
    public function __construct(
        private readonly OidcConfigWriter $writer,
        private readonly OidcSettings     $settings,
    ) {
    }

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $config = $this->writer->current();

        // The action has to be explicit: with none, a form inside a Turbo
        // Frame submits to the document URL, /admin?section=sso, and quietly
        // does nothing. The trap IntegrationProviderController records.
        $form = $this->createForm(OidcConfigType::class, $config, [
            'action' => $this->generateUrl('app_admin_sso_settings'),
        ]);
        $form->handleRequest($request);

        $saved = false;

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->writer->save($config, $form);

            // Re-created rather than reused: the secret field is unmapped and
            // still holds what was typed, and re-rendering it would put a
            // credential back on screen after it had been stored. It also
            // brings the "forget the secret" box in or out to match.
            $form  = $this->createForm(OidcConfigType::class, $config, [
                'action' => $this->generateUrl('app_admin_sso_settings'),
            ]);
            $saved = true;
        }

        return $this->render('admin/sso/_frame.html.twig', [
            'config' => $config,
            'form'   => $form,
            'saved'  => $saved,
            'source' => $this->settings->source(),
        ]);
    }
}
