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
 * Whether search starts limited to the workspace on screen (#783) —
 * User::$searchAutoscope. Beside the account groups, which are what a
 * workspace's scope is made of.
 */
#[Route('/settings/search-scope', name: 'app_settings_search_scope', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class SearchScopeController extends AbstractController
{
    use ChecksCsrf;

    public function __invoke(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'settings-search-scope');

        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $user->searchAutoscope = '1' === $request->request->getString('autoscope');
        $em->flush();

        return $this->redirectToRoute('app_settings_page', ['page' => 'mail-search']);
    }
}
