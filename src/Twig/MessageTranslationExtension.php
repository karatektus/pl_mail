<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Ai\StoredTranslation;
use App\Domain\DTO\Ai\TranslationSource;
use App\Domain\Enum\Ai\TranslationLanguage;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Ai\MessageTranslator;
use App\Service\Ai\TranslationStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What a message body needs to offer, or show, a translation (#713).
 *
 * Functions rather than variables for AiAvailabilityExtension's reason: the
 * body is drawn by the conversation view, the single-message pane and Turbo
 * Streams, and no one controller sits behind all of them.
 *
 * ONCE PER PAGE, NOT ONCE PER MESSAGE. The conversation view asks both
 * functions for every message it draws, and ThreadViewQueryBudgetTest refuses
 * a page whose queries grow with the conversation: so whether the person may
 * translate, into what, and with which model and prompt is read once, and the
 * stored translations of a whole conversation are fetched in one query when
 * its first message asks. Kept per request and forgotten in reset(): in
 * FrankenPHP's worker mode this object outlives the request, and a memo that
 * survived it would offer one person's language to the next (see #716).
 */
final class MessageTranslationExtension extends AbstractExtension implements ResetInterface
{
    /** @var array{into: TranslationLanguage, intoLabel: string, model: string, prompt: string}|false|null false: not offered */
    private array|false|null $context = null;

    /** @var array<int, array<int, array{translation: string, source_hash: string, created_at: string}>> stored rows by thread id, then message id */
    private array $rows = [];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TranslationStore  $store,
        private readonly Security          $security,
        private readonly RequestStack      $requests,
    ) {
    }

    public function reset(): void
    {
        $this->context = null;
        $this->rows    = [];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('message_translation_offered', $this->offered(...)),
            new TwigFunction('message_translation', $this->translation(...)),
        ];
    }

    /** Whether "Translate" is offered for this message. */
    public function offered(Message $message): bool
    {
        return false !== $this->context() && $this->translator->allowsMessage($message);
    }

    /**
     * The language the reader gets, and the translation already stored for it,
     * or null where translating is not offered.
     *
     * @return array{into: string, intoLabel: string, model: string, stored: StoredTranslation|null, partial: bool, leavesOutQuote: bool}|null
     */
    public function translation(Message $message): ?array
    {
        $context = $this->context();

        if (false === $context || false === $this->translator->allowsMessage($message)) {
            return null;
        }

        $source = null;
        $stored = $this->storedFor($message, $context, $source);

        return [
            'into'           => $context['into']->value,
            'intoLabel'      => $context['intoLabel'],
            'model'          => $context['model'],
            'stored'         => $stored,
            'partial'        => true === $source?->isPartial,
            'leavesOutQuote' => true === $source?->leavesOutQuote,
        ];
    }

    /** @return array{into: TranslationLanguage, intoLabel: string, model: string, prompt: string}|false */
    private function context(): array|false
    {
        if (null !== $this->context) {
            return $this->context;
        }

        $user = $this->security->getUser();

        if (false === $user instanceof User || false === $this->translator->isAvailableFor($user)) {
            return $this->context = false;
        }

        $locale = $this->requests->getCurrentRequest()?->getLocale();
        $into   = MessageTranslator::targetFor($user, $locale);

        return $this->context = [
            'into'      => $into,
            'intoLabel' => $into->label($locale ?? 'en'),
            'model'     => $this->translator->model(),
            'prompt'    => $this->translator->promptFingerprint(),
        ];
    }

    /**
     * This message's stored translation. The rows of its whole conversation
     * are fetched by the thread's id when its first message asks; the text it
     * would be translated from is read only for a message that has a row, and
     * handed back in $source for what the page says about it.
     *
     * @param array{into: TranslationLanguage, intoLabel: string, model: string, prompt: string} $context
     */
    private function storedFor(Message $message, array $context, ?TranslationSource &$source): ?StoredTranslation
    {
        $source = null;
        $thread = $message->thread;
        $id     = (int) $message->id;

        if (null === $thread) {
            return $this->store->forMessage($id, $context['into'], $context['model'], $context['prompt'], static function () use ($message, &$source): string {
                return ($source = MessageTranslator::sourceOf($message))->hash();
            });
        }

        $rows = $this->rows[(int) $thread->id] ??= $this->store->rowsForThread((int) $thread->id, $context['into'], $context['model'], $context['prompt']);

        if (false === isset($rows[$id])) {
            return null;
        }

        $source = MessageTranslator::sourceOf($message);

        return TranslationStore::stored($rows[$id], $context['into'], $source->hash());
    }
}
