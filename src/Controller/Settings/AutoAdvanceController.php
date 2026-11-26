<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\AutoAdvance;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where the reading pane goes after the open conversation leaves the list.
 *
 * Only the choice is stored here. The pane reads it from <body> and does the
 * moving itself — see assets/controllers/mail/mail_pane_controller.js#leaving.
 */
#[IsGranted('ROLE_USER')]
final class AutoAdvanceController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/auto-advance', name: 'app_settings_auto_advance_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-auto-advance');

        // Read only when posted, like every control on this page: a form that
        // did not mention the choice must not move it.
        if (true === $request->request->has('advance')) {
            $user->autoAdvance = AutoAdvance::fromSetting($request->request->get('advance'));
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_page', ['page' => 'layout']);
    }
}
