<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Domain\Ai\SourceLanguageLine;
use App\Domain\DTO\Ai\TranslationSource;
use App\Domain\Enum\Ai\TranslationLanguage;
use App\Domain\Helper\RequestTimeLimit;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Ai\MessageTranslator;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\TranslationStore;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Translating one message, streamed as it is written (#713).
 *
 * ThreadSummaryController's ordinary path, and its docblocks give the reasons
 * this repeats: everything that can refuse happens before the response exists;
 * the session is released before a stream that can last minutes; NDJSON frames
 * (state, ping, token, error, done) read by assets/ai/ndjson.js; a reader who
 * goes away does not stop the translation, which is finished and stored, so the
 * message opens translated next time instead of costing the wait again.
 *
 * No stop button and no queued variant: a translation is one message, and
 * "Show original" while it is being written only hides it.
 */
final class MessageTranslationController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TranslationStore  $store,
        private readonly LoggerInterface   $logger,
    ) {
    }

    #[Route('/mail/message/{id}/translation', name: 'app_mail_message_translation', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function __invoke(Request $request, Message $message): Response
    {
        $this->assertCsrf($request, 'message_translation');

        $user = $this->getUser();

        if (false === $user instanceof User || false === $this->translator->isAvailableFor($user)) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_CONFLICT);
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        if (false === $this->translator->isAvailableForMessage($user, $message)) {
            return new JsonResponse(['error' => 'encrypted'], Response::HTTP_CONFLICT);
        }

        $source = MessageTranslator::sourceOf($message);

        if ('' === trim($source->text)) {
            return new JsonResponse(['error' => 'empty'], Response::HTTP_CONFLICT);
        }

        $into       = MessageTranslator::targetFor($user, $request->getLocale());
        $model      = $this->translator->model();
        $promptHash = $this->translator->promptFingerprint();

        // Released before the stream for ThreadSummaryController's reason: the
        // lock would hold every other request of this person for minutes.
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }

        $locale = $request->getLocale();

        $response = new StreamedResponse(function () use ($user, $message, $into, $source, $model, $promptHash, $locale): void {
            $this->generate($user, $message, $into, $source, $model, $promptHash, $locale);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function generate(User $user, Message $message, TranslationLanguage $into, TranslationSource $source, string $model, string $promptHash, string $locale): void
    {
        ignore_user_abort(true);

        RequestTimeLimit::hold(MessageTranslator::TIME_LIMIT_SECONDS);

        $tokens = $this->translator->stream($user, $message, $into, $source);

        if (null === $tokens) {
            $this->frame(['type' => 'error', 'kind' => 'no_answer']);

            return;
        }

        $this->frame([
            'type'  => 'state',
            'value' => true === $this->translator->isModelWarm() ? 'generating' : 'waiting',
        ]);

        $since         = microtime(true);
        $lostTheReader = false;

        foreach ($tokens as $token) {
            if (true === $lostTheReader) {
                continue;
            }

            $this->frame(
                '' === $token
                    ? ['type' => 'ping', 'elapsed' => (int) round(microtime(true) - $since)]
                    : ['type' => 'token', 'text' => $token],
            );

            if (0 !== connection_aborted()) {
                $lostTheReader = true;

                $this->logger->info('Message translation lost its reader; finishing it anyway', [
                    'message'         => $message->id,
                    'model'           => $model,
                    'elapsed_seconds' => (int) round(microtime(true) - $since),
                ]);
            }
        }

        $translated = $tokens->getReturn();
        $result     = $translated->chat;

        if (false === $result->succeeded || null === $result->content) {
            $kind = $result->errorKind ?? OllamaClient::ERROR_UNEXPECTED;

            $this->logger->error('Message translation failed', [
                'message' => $message->id,
                'kind'    => $kind,
                'model'   => $model,
                'chars'   => mb_strlen($source->text),
            ]);

            if (false === $lostTheReader) {
                $this->frame(['type' => 'error', 'kind' => $kind]);
            }

            return;
        }

        $this->store->save((int) $message->id, $into, $result->content, $translated->sourceLanguage, $source->hash(), $model, $promptHash);

        if (true === $lostTheReader) {
            return;
        }

        // The stored text again, so what the reader sees is what the next
        // opening of this message shows.
        $this->frame([
            'type'           => 'done',
            'text'           => $result->content,
            'from'           => $translated->sourceLanguage,
            'fromLabel'      => null === $translated->sourceLanguage ? null : SourceLanguageLine::nameOf($translated->sourceLanguage, $locale),
            'partial'        => $source->isPartial,
            'leavesOutQuote' => $source->leavesOutQuote,
        ]);
    }

    /** @param array<string, mixed> $frame */
    private function frame(array $frame): void
    {
        echo json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";

        if (0 < ob_get_level()) {
            ob_flush();
        }

        flush();
    }
}
