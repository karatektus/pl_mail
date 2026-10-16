<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Integration\ServiceKind;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Form\CalDavConnectType;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Service\AddressBook\AddressBookLister;
use App\Service\AddressBook\AddressBookSubscriber;
use App\Service\Calendar\Subscription\CalDavConnector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Contacts: connecting a CardDAV server and choosing which of its
 * address books to mirror.
 *
 * The calendar screen's flow, step for step, because it is the same flow to a
 * person: address and credential, then the list of what the server offers with
 * tick boxes. Connecting goes through CalDavConnector, so the
 * reuse-a-mail-password decision and the probe-on-save rule are the ones the
 * calendar connection already has rather than a second copy of them.
 *
 * Every mutation answers with one stream that redraws the section, the way
 * CalendarSettingsController::calendarListStream() does.
 */
#[Route('/settings/contacts', name: 'app_settings_contacts_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ContactSettingsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly IntegrationRepository  $integrations,
        private readonly AddressBookRepository  $books,
        private readonly AddressBookSubscriber  $subscriber,
        private readonly AddressBookLister      $lister,
        private readonly CalDavConnector        $connector,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Address, then credential, then — on success — the address books to tick,
     * in the same modal. See CalendarSettingsController::connectCalDav() for
     * why the dialog stays open across its own submit.
     */
    #[Route('/carddav/connect', name: 'carddav_connect', methods: ['GET', 'POST'])]
    public function connect(Request $request): Response
    {
        $user = $this->currentUser();

        $integration          = new Integration($user, Provider::CardDav, Provider::CardDav->label());
        $integration->baseUrl = $this->connector->suggestedAddress($user);

        $form = $this->createForm(CalDavConnectType::class, $integration, [
            'action'           => $this->generateUrl('app_settings_contacts_carddav_connect'),
            'lending_accounts' => $this->connector->lendingAccounts($user),
            'protocol'         => 'carddav',
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $error = $this->connector->connect($integration, $form);

            if (null === $error) {
                return $this->renderSubscribeList($integration);
            }

            return $this->renderConnectForm($form, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderConnectForm(
            $form,
            null,
            true === $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /**
     * The tick boxes, and applying them.
     *
     * Only the ticked remote ids are read from the POST; everything about each
     * book comes from a fresh discovery — see AddressBookSubscriber.
     */
    #[Route('/subscribe/{id}', name: 'subscribe', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function subscribe(Request $request, int $id): Response
    {
        $integration = $this->connectionFor($id);

        if (false === $request->isMethod('POST')) {
            return $this->renderSubscribeList($integration);
        }

        $this->assertCsrf($request, 'contacts-subscribe' . $id);

        try {
            $change = $this->subscriber->apply($integration, $this->tickedRemoteIds($request));
        } catch (CalendarSyncException $e) {
            return $this->renderSubscribeList($integration, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->listStream(
            0 === $change['subscribed'] && 0 === $change['unsubscribed']
                ? 'settings.contacts.toast.unchanged'
                : 'settings.contacts.toast.saved',
        );
    }

    /**
     * Remove a connection and every book mirrored through it. Nothing is
     * discovered on the way out — the connection somebody most wants gone is
     * the one that can no longer be listed. The books and their cards go by
     * cascade.
     */
    #[Route('/carddav/{id}/disconnect', name: 'carddav_disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disconnect(Request $request, int $id): Response
    {
        $integration = $this->connectionFor($id);
        $this->assertCsrf($request, 'contacts-disconnect' . $id);

        $this->em->remove($integration);
        $this->em->flush();

        return $this->listStream('settings.contacts.toast.disconnected');
    }

    /** Ask for one book to be brought up to date now, through the worker. */
    #[Route('/book/{id}/sync', name: 'sync', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sync(Request $request, int $id): Response
    {
        $book = $this->books->find($id);

        if (null === $book || $book->usr !== $this->currentUser()) {
            throw $this->createAccessDeniedException();
        }

        $this->assertCsrf($request, 'contacts-sync' . $id);

        $this->bus->dispatch(new SyncAddressBookMessage((int) $book->id));

        return $this->listStream('settings.contacts.toast.sync_queued');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** A contacts connection of this user's, or a refusal. */
    private function connectionFor(int $id): Integration
    {
        $integration = $this->integrations->find($id);

        if (null === $integration
            || $integration->usr !== $this->currentUser()
            || ServiceKind::Contacts !== $integration->provider->kind()) {
            throw $this->createAccessDeniedException();
        }

        return $integration;
    }

    /**
     * @return list<string>
     */
    private function tickedRemoteIds(Request $request): array
    {
        /** @var array<mixed> $ticked */
        $ticked = $request->request->all('remoteIds');

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $ticked),
            static fn (string $value): bool => '' !== $value,
        ));
    }

    private function renderSubscribeList(
        Integration $integration,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $offers = [];

        if (null === $error) {
            try {
                $offers = $this->subscriber->discover($integration);
            } catch (CalendarSyncException $e) {
                $error  = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('contacts/_subscribe.html.twig', [
            'offers'      => $offers,
            'integration' => $integration,
            'error'       => $error,
        ], new Response(status: $status));
    }

    private function renderConnectForm(FormInterface $form, ?string $error, int $status): Response
    {
        return $this->render('contacts/_carddav_form.html.twig', [
            'form'  => $form,
            'error' => $error,
        ], new Response(status: $status));
    }

    private function listStream(string $toastMessage): Response
    {
        return $this->render('settings/contacts/_list.stream.html.twig', [
            'toastMessage' => $toastMessage,
            ...$this->lister->sectionFor($this->currentUser()),
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
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
