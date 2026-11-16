<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Infrastructure\Messaging\Message\QueueSenderPicturesMessage;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Sender pictures: whether this server may look up sender pictures
 * on the internet (#639). See SenderPictureSettings for why it is the
 * administrator's call and starts off.
 *
 * One action for GET and POST and a Turbo Frame of its own, as Admin → Push
 * and Admin → Address do it.
 *
 * **Turning it on looks up every user's recent senders**, one queued job per
 * user (QueueSenderPicturesMessage), for the reason the user's own switch
 * does: otherwise only mail arriving after the click would ever get a picture.
 * Each job still asks that user's switches, so a user who has Gravatar off is
 * not looked up on Gravatar because the administrator said yes.
 */
#[Route('/admin/sender-pictures', name: 'app_admin_sender_pictures_')]
#[IsGranted('ROLE_ADMIN')]
final class SenderPictureAdminController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly SenderPictureSettingsRepository $settings,
        private readonly UserRepository                  $users,
        private readonly EntityManagerInterface          $em,
        private readonly MessageBusInterface             $bus,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $settings = $this->settings->currentOrNew();
        $saved    = false;

        if (true === $request->isMethod('POST')) {
            $this->assertCsrf($request, 'admin-sender-pictures');

            $enable     = '1' === (string) $request->request->get('lookupsEnabled');
            $switchedOn = true === $enable && false === $settings->lookupsEnabled;

            $settings->lookupsEnabled = $enable;
            $this->em->persist($settings);
            $this->em->flush();

            if (true === $switchedOn) {
                foreach ($this->users->findAll() as $user) {
                    if (null !== $user->id) {
                        $this->bus->dispatch(new QueueSenderPicturesMessage($user->id));
                    }
                }
            }

            $saved = true;
        }

        return $this->render('admin/sender_pictures/_frame.html.twig', [
            'enabled' => $settings->lookupsEnabled,
            'saved'   => $saved,
        ]);
    }
}
