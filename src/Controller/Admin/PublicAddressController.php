<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Admin\PublicUrlType;
use App\Service\Setup\PublicUrlSetting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Address: where plMail is reached from outside, after setup.
 *
 * The setup screen asks for this once and there was no way to answer it again.
 * That left an install restored from another machine's backup telling every
 * browser to subscribe to live updates at the old machine's address, with the
 * only remedy an environment variable in the compose file.
 *
 * One action for GET and POST and a Turbo Frame of its own, as Admin → Push
 * does it and for the reasons recorded there.
 *
 * Saving does not restart anything. The frame says a restart is needed and
 * offers the web container's, which is the one that can be done from here; the
 * rest of the stack is the operator's to restart, as it is after a restored
 * backup. PublicUrlSetting records why the change is not applied live.
 */
#[Route('/admin/address', name: 'app_admin_address_')]
#[IsGranted('ROLE_ADMIN')]
final class PublicAddressController extends AbstractController
{
    public function __construct(
        private readonly PublicUrlSetting $publicUrl,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        // Prefilled with what is on file rather than what is in force: the
        // form edits the file, and after a save that is still waiting for its
        // restart the two differ.
        $form = $this->createForm(PublicUrlType::class, [
            'publicUrl' => $this->publicUrl->stored() ?? $this->publicUrl->current(),
        ], [
            // Explicit for the reason PushSettingsController gives: inside a
            // Turbo Frame a form with no action posts to the document URL.
            'action' => $this->generateUrl('app_admin_address_settings'),
        ]);
        $form->handleRequest($request);

        $saved = false;

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->publicUrl->save((string) $form->get('publicUrl')->getData());

            $saved = true;
        }

        return $this->render('admin/address/_frame.html.twig', [
            'form'    => $form,
            'saved'   => $saved,
            'current' => $this->publicUrl->current(),
            'stored'  => $this->publicUrl->stored(),
        ]);
    }
}
