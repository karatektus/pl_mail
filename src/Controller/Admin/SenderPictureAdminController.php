<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\User\LibravatarDnsStatus;
use App\Domain\Helper\PersonalMailDomains;
use App\Infrastructure\Messaging\Message\QueueSenderPicturesMessage;
use App\Repository\Mail\Account\AccountLibravatarRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Setup\PublicUrlSetting;
use App\Service\User\Libravatar\LibravatarDirectory;
use App\Service\User\Libravatar\LibravatarDnsCheck;
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
 *
 * **The second card is the other direction** (#642): this server answering
 * Libravatar requests for its own users. Its switch, the SRV record every
 * domain needs and a button that checks them. Everything an administrator
 * could get wrong is on the card — the record's exact text, the host it must
 * name, that the host needs a real certificate and to be reachable from
 * outside — because the failure mode is silence: a client that cannot find
 * or reach the server just shows its own fallback, and nobody is told why.
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
        private readonly AccountLibravatarRepository     $accounts,
        private readonly LibravatarDirectory             $directory,
        private readonly LibravatarDnsCheck              $dns,
        private readonly PublicUrlSetting                $publicUrl,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $settings = $this->settings->currentOrNew();
        $saved    = null;

        if (true === $request->isMethod('POST')) {
            $this->assertCsrf($request, 'admin-sender-pictures');

            // One field per form, each read only when posted, so the two
            // switches on this page cannot move each other.
            if (true === $request->request->has('lookupsEnabled')) {
                $enable     = '1' === (string) $request->request->get('lookupsEnabled');
                $switchedOn = true === $enable && false === $settings->lookupsEnabled;

                $settings->lookupsEnabled = $enable;
                $saved                    = 'lookups';

                if (true === $switchedOn) {
                    foreach ($this->users->findAll() as $user) {
                        if (null !== $user->id) {
                            $this->bus->dispatch(new QueueSenderPicturesMessage($user->id));
                        }
                    }
                }
            }

            if (true === $request->request->has('libravatarEnabled')) {
                $settings->libravatarEnabled = '1' === (string) $request->request->get('libravatarEnabled');
                $saved                       = 'libravatar';

                $this->directory->forget();
            }

            $this->em->persist($settings);
            $this->em->flush();
        }

        return $this->frame($settings->lookupsEnabled, $settings->libravatarEnabled, $saved, null);
    }

    /**
     * The "Check DNS" button: resolves every domain's SRV record and draws
     * the card again with what each one says. A POST, with the frame's
     * token, because it makes the server send a DNS query per domain on
     * request, and a GET would let any page do that with an <img>.
     */
    #[Route('/libravatar-dns', name: 'libravatar_dns', methods: ['POST'])]
    public function checkDns(Request $request): Response
    {
        $this->assertCsrf($request, 'admin-sender-pictures');

        $settings = $this->settings->currentOrNew();
        $host     = $this->publicHost();
        $results  = [];

        foreach ($this->ownDomains() as $domain) {
            $results[$domain] = $this->dns->check($domain, $host);
        }

        return $this->frame($settings->lookupsEnabled, $settings->libravatarEnabled, null, $results);
    }

    /**
     * @param array<string, array{status: LibravatarDnsStatus, found: ?string}>|null $checked
     */
    private function frame(bool $lookups, bool $libravatar, ?string $saved, ?array $checked): Response
    {
        $publicUrl = $this->publicUrl->current();

        return $this->render('admin/sender_pictures/_frame.html.twig', [
            'enabled'           => $lookups,
            'libravatarEnabled' => $libravatar,
            'saved'             => $saved,
            'publicUrl'         => $publicUrl,
            'publicHost'        => $this->publicHost(),
            'httpsPublicUrl'    => null !== $publicUrl && true === str_starts_with(strtolower($publicUrl), 'https://'),
            'domains'           => $this->ownDomains(),
            'providerDomains'   => $this->providerDomains(),
            'checked'           => $checked,
        ]);
    }

    /**
     * The domains an administrator could publish a record at: every domain a
     * user has an address at, less the mail providers'. Those are drawn apart,
     * with no record, because a record "exactly as shown" for gmail.com is one
     * only Google can add, and offering it said otherwise.
     *
     * @return list<string>
     */
    private function ownDomains(): array
    {
        return array_values(array_filter(
            $this->accounts->addressDomains(),
            static fn (string $domain): bool => false === PersonalMailDomains::contains($domain),
        ));
    }

    /** @return list<string> */
    private function providerDomains(): array
    {
        return array_values(array_filter(
            $this->accounts->addressDomains(),
            static fn (string $domain): bool => true === PersonalMailDomains::contains($domain),
        ));
    }

    /** The host Libravatar clients are told to fetch from: Admin → Address's. */
    private function publicHost(): ?string
    {
        $host = parse_url((string) $this->publicUrl->current(), PHP_URL_HOST);

        return true === is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
