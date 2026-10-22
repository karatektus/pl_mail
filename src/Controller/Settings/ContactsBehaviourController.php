<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Contacts\ContactsSwitchSkip;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Contacts → Behaviour: how the topbar's contacts button moves
 * between views. CalendarBehaviourController's choice, for the address book,
 * and read the same way: only when posted, and only as one of the three.
 */
#[IsGranted('ROLE_USER')]
final class ContactsBehaviourController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/contacts-behaviour', name: 'app_settings_contacts_behaviour_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-contacts-behaviour');

        $skip = ContactsSwitchSkip::tryFrom((string) $request->request->get('skip'));

        if (null !== $skip) {
            $user->contactsSwitchSkip = $skip;
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'contacts']);
    }
}
