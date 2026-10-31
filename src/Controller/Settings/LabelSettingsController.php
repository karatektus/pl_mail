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
 * Folders in Labels, on or off (#221 step 3): whether the sidebar's LABELS
 * section lists folders too, or holds the tags alone and leaves folders
 * under their account (User::$foldersInLabels). Off by default.
 */
#[IsGranted('ROLE_USER')]
final class LabelSettingsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/labels/folders-in-labels', name: 'settings_labels_folders_toggle', methods: ['POST'])]
    public function toggleFoldersInLabels(Request $request): Response
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->assertCsrf($request, 'labels_folders_in_labels');

        $user->foldersInLabels = false === $user->foldersInLabels;

        $this->em->flush();

        return $this->render('settings/labels/_folders_in_labels_control.html.twig');
    }
}
