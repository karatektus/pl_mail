<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Service\Mail\ForwardAgents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Compose → Agents: the list "Forward to agent" picks from.
 *
 * Two plain form posts that redirect back to the card, like the compose
 * behaviour card above it. The list itself is ForwardAgents'.
 */
#[Route('/settings/forward-agents', name: 'app_settings_forward_agents_')]
#[IsGranted('ROLE_USER')]
final class ForwardAgentsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly ForwardAgents          $agents,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface    $translator,
    ) {
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->assertCsrf($request, 'settings-forward-agents');

        $added = $this->agents->add(
            $this->currentUser(),
            (string) $request->request->get('name', ''),
            (string) $request->request->get('address', ''),
        );

        if (false === $added) {
            $this->addFlash('error', $this->translator->trans('settings.forward_agents.refused', [
                '%max%' => ForwardAgents::MAX_AGENTS,
            ]));
        }

        $this->em->flush();

        return $this->backToCard();
    }

    #[Route('/remove', name: 'remove', methods: ['POST'])]
    public function remove(Request $request): Response
    {
        $this->assertCsrf($request, 'settings-forward-agents');

        $this->agents->remove($this->currentUser(), (string) $request->request->get('address', ''));
        $this->em->flush();

        return $this->backToCard();
    }

    private function backToCard(): Response
    {
        return $this->redirect(
            $this->generateUrl('app_settings_page', ['page' => 'compose']) . '#card-forward-agents',
        );
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
