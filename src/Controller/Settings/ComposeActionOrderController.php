<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\ComposeAction;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The order of the compose window's action bar, as arranged in the window
 * itself ("Arrange buttons" under ⋮, then Done).
 *
 * JSON in, nothing out: the window already shows the order it is posting, so
 * there is nothing to send back but "stored". `{"order": [...]}` stores an
 * order, `{"order": null}` forgets it (Reset, then Done).
 */
#[IsGranted('ROLE_USER')]
final class ComposeActionOrderController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/compose/action-order', name: 'app_settings_compose_action_order', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'ajax');

        $user->setComposeActionOrder($this->order($request->toArray()['order'] ?? null));
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The posted order, each entry of which has to be a button the bar has.
     *
     * An unknown name is somebody editing the request, so it is refused rather
     * than dropped, for the reason SidebarMoreController gives: a save that
     * stored part of what it was sent leaves the bar and the bag disagreeing.
     *
     * @return list<ComposeAction>|null
     */
    private function order(mixed $posted): ?array
    {
        if (null === $posted) {
            return null;
        }

        if (false === is_array($posted) || false === array_is_list($posted)) {
            throw $this->createNotFoundException('Not an order.');
        }

        $order = [];

        foreach ($posted as $value) {
            $action = is_string($value) ? ComposeAction::tryFrom($value) : null;

            if (null === $action || true === in_array($action, $order, true)) {
                throw $this->createNotFoundException('Not a button the compose window has.');
            }

            $order[] = $action;
        }

        return $order;
    }
}
