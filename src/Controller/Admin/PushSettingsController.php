<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Admin\FcmConfigType;
use App\Service\Push\FcmConfigWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Push: the Firebase project this installation delivers Android
 * notifications through.
 *
 * One action for GET and POST, as the rest of the admin area does it: the form
 * has to re-render with what was typed when a paste is rejected, and a separate
 * GET could not do that — which matters more here than anywhere else, because
 * the thing being typed is a two-kilobyte JSON blob nobody wants to paste twice.
 *
 * A Turbo Frame rather than a modal, unlike the integration providers. Those
 * are a list of eleven services where the form is a detour; this is one form
 * that IS the section, and putting it behind a button would add a click to
 * every visit for no gain.
 *
 * Web Push is deliberately absent from this page. Its VAPID keys are env vars
 * generated once by `app:push:generate-vapid-keys`, and a settings screen that
 * showed them read-only beside an editable Firebase key would suggest they can
 * be changed here. The page says which transports are live; where each one is
 * configured is documented rather than implied.
 */
#[Route('/admin/push', name: 'app_admin_push_')]
#[IsGranted('ROLE_ADMIN')]
final class PushSettingsController extends AbstractController
{
    public function __construct(
        private readonly FcmConfigWriter $writer,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $config = $this->writer->current();

        // The action has to be explicit. A Symfony form with no action submits
        // to the *document* URL, and this one renders inside a Turbo Frame — so
        // the POST would go to /admin?section=push and quietly do nothing. The
        // same trap IntegrationProviderController records.
        $form = $this->createForm(FcmConfigType::class, $config, [
            'action' => $this->generateUrl('app_admin_push_settings'),
        ]);
        $form->handleRequest($request);

        $saved = false;

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->writer->save($config, $form);

            // Re-created rather than reused: the key field is unmapped and
            // still holds what was pasted, and re-rendering it would put a
            // credential back on screen after it had been stored.
            $form  = $this->createForm(FcmConfigType::class, $config, [
                'action' => $this->generateUrl('app_admin_push_settings'),
            ]);
            $saved = true;
        }

        return $this->render('admin/push/_frame.html.twig', [
            'config' => $config,
            'form'   => $form,
            'saved'  => $saved,
        ]);
    }
}
