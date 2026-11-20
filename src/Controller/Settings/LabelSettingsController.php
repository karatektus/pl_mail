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
 * The two switches in the labels settings, both about how the sidebar shows
 * labels and both off by default:
 *
 *  - Folders in Labels (#221 step 3): whether the merged LABELS section lists
 *    folders too, or holds the tags alone and leaves folders under their
 *    account (User::$foldersInLabels).
 *  - By account (#690): whether labels are listed under each account instead
 *    of merged in LABELS (User::$labelsByAccount).
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
        $user = $this->currentUser();

        $this->assertCsrf($request, 'labels_folders_in_labels');

        $user->foldersInLabels = false === $user->foldersInLabels;

        $this->em->flush();

        return $this->render('settings/labels/_folders_in_labels_control.html.twig');
    }

    /**
     * Answers with the whole switch bar rather than the one switch: Folders in
     * Labels means nothing while labels are listed by account, so it is drawn
     * disabled then, and flipping this one has to redraw both.
     */
    #[Route('/settings/labels/by-account', name: 'settings_labels_by_account_toggle', methods: ['POST'])]
    public function toggleLabelsByAccount(Request $request): Response
    {
        $user = $this->currentUser();

        $this->assertCsrf($request, 'labels_by_account');

        $user->labelsByAccount = false === $user->labelsByAccount;

        $this->em->flush();

        return $this->render('settings/labels/_view_controls.html.twig');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
