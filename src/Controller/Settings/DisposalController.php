<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Mail → Disposal: what a person is offered for getting rid of mail.
 *
 * Today that is one switch — whether the buttons that delete mail for good are
 * offered at all, on a row and above a selection. Off until somebody turns it
 * on: it is the one action in plMail with no Undo.
 *
 * The routes that delete for good check the switch themselves
 * (ThreadStatusController::purge, BulkStatusController::bulk), so this
 * controller only records the choice; it is not what enforces it.
 */
#[IsGranted('ROLE_USER')]
final class DisposalController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/disposal', name: 'app_settings_disposal_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-disposal');

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention the switch must not move it.
        if (true === $request->request->has('deleteNow')) {
            $user->deleteNowButtons = '1' === (string) $request->request->get('deleteNow');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'disposal']);
    }
}
