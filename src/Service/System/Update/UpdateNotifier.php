<?php

declare(strict_types=1);

namespace App\Service\System\Update;

use App\Domain\DTO\System\PublishedBuild;
use App\Domain\DTO\System\UpdateStatus;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use App\Service\System\AppVersion;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tell every administrator that the channel has a newer build: a notification
 * on each device they have registered for push.
 *
 * The same stack calendar alerts use (see PushAlertChannel for why there is not
 * a second one), with a payload type of its own that public/sw.js shows and
 * nothing else acts on. Worded per administrator, since each has their own
 * interface language and the push leaves before any page could translate it.
 *
 * An administrator with no registered device is not an error: the admin page
 * and its header say the same thing to whoever opens them next.
 */
final readonly class UpdateNotifier
{
    /** Matched by public/sw.js, the only other place it appears. */
    public const string PAYLOAD_TYPE = 'UpdateAvailable';

    /** Where the notification opens: the page that says what changed. */
    public const string URL = '/admin?section=updates';

    public function __construct(
        private UserRepository             $users,
        private PushSubscriptionRepository $subscriptions,
        private PushSenderRegistry         $senders,
        private TranslatorInterface        $translator,
        private AppVersion                 $version,
        private LoggerInterface            $logger,
    ) {
    }

    /** @return int how many devices accepted the notification */
    public function notify(UpdateStatus $status): int
    {
        if (false === $this->senders->anyConfigured()) {
            return 0;
        }

        $delivered = 0;

        foreach ($this->users->findAdmins() as $admin) {
            $devices = null === $admin->id ? [] : $this->subscriptions->findDeliverableForUser($admin->id);

            if ([] === $devices) {
                continue;
            }

            $payload = [
                '@type' => self::PAYLOAD_TYPE,
                'title' => $this->translator->trans('admin.updates.push.title', [
                    '%version%' => self::label($status->latest),
                ], null, $admin->locale),
                'body'  => $this->translator->trans('admin.updates.push.body', [
                    '%running%' => $this->running(),
                ], null, $admin->locale),
                'url'   => self::URL,
                // The build's identity, so a push the browser replays after
                // waking replaces the notification rather than stacking a copy.
                'tag'   => $status->latest->revision,
            ];

            foreach ($devices as $device) {
                $sender = $this->senders->for($device);

                if (null !== $sender && true === $sender->isConfigured() && true === $sender->send($device, $payload)) {
                    ++$delivered;
                }
            }
        }

        $this->logger->info('UpdateNotifier: administrators told about a newer build', [
            'version'   => $status->latest->version,
            'revision'  => $status->latest->shortRevision(),
            'delivered' => $delivered,
        ]);

        return $delivered;
    }

    /**
     * A build as a person names it: a release by its version, a main build by
     * its branch and commit, since `main` alone names every one of them.
     */
    public static function label(PublishedBuild $build): string
    {
        return true === $build->isRelease()
            ? $build->version
            : sprintf('%s · %s', $build->version, $build->shortRevision());
    }

    private function running(): string
    {
        $commit = $this->version->commit();

        return null === $commit || 1 === preg_match('/^v?\d+\.\d+\.\d+$/', $this->version->label())
            ? $this->version->label()
            : sprintf('%s · %s', $this->version->label(), $commit);
    }
}
