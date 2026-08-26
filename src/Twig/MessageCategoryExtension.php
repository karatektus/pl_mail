<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Service\Mail\MessageCategorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Why a message landed in the category it did, for the details popover.
 *
 * The answer is recomputed from the same class that decided it in the first
 * place, rather than read from a column: a stored reason would be written by
 * whatever version of the rules ran at sync time, and would quietly go on
 * explaining a decision the current rules no longer make.
 *
 * The correspondent check is per-address rather than the whole set the
 * categoriser is normally handed — one message is being explained, not a
 * mailbox classified.
 */
final class MessageCategoryExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageCategorizer $categorizer,
        private readonly ContactRepository  $contacts,
        private readonly Security           $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('category_explanation', $this->explain(...)),
            new TwigFunction('category_explanation_local', $this->explainLocal(...)),
        ];
    }

    /**
     * @return array{category: string, reason: string, signal: string|null}|null
     *         null when there is no category to explain
     */
    public function explain(Message $message): ?array
    {
        return $this->describe($message, ignoreProviderLabels: false);
    }

    /**
     * What THIS application's rules would have said, ignoring Gmail's labels.
     *
     * Only interesting on a Gmail mailbox, where the provider's own CATEGORY_*
     * labels are authoritative and the local cascade therefore never runs. The
     * day those labels stop arriving — an account moved off Gmailify, a mailbox
     * migrated to plain IMAP — the local rules take over at once, having never
     * been looked at against real mail. Rendering both answers together makes
     * that difference something to tune now rather than discover then.
     *
     * @return array{category: string, reason: string, signal: string|null}|null
     */
    public function explainLocal(Message $message): ?array
    {
        return $this->describe($message, ignoreProviderLabels: true);
    }

    /**
     * @return array{category: string, reason: string, signal: string|null}|null
     */
    private function describe(Message $message, bool $ignoreProviderLabels): ?array
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return null;
        }

        $from = mb_strtolower(trim((string) $message->fromAddress));

        $explanation = $this->categorizer->explain(
            $message,
            $this->contacts->isCorrespondent($user, $from) ? [$from => true] : [],
            $ignoreProviderLabels,
        );

        return [
            'category' => $explanation['category']->value,
            'reason'   => $explanation['reason'],
            'signal'   => $explanation['signal'],
        ];
    }
}
