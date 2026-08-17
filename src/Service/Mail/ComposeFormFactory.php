<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ComposeContext;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Form\ComposeType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The compose form, built the one way.
 *
 * Every window in the application comes through here — new, reply, reply-all,
 * forward, autosave, send and schedule — and the two things they must agree on
 * are the form NAME and what the editor is handed as a body. Both were a
 * private method on ComposeController and are now reachable without a route,
 * which is what makes the naming rule below testable.
 */
final readonly class ComposeFormFactory
{
    public function __construct(
        private FormFactoryInterface $forms,
        private InlineImageRewriter  $inlineImages,
    ) {}

    /**
     * Inline windows get their own form name so their DOM ids cannot collide
     * with a dock window open at the same time (`compose_inline_subject`
     * against `compose_subject`). The CSRF token id is shared, so tokens
     * interchange between the two.
     *
     * @param list<string> $groups validation groups; 'send' adds the rules that
     *                             only matter once the mail is actually going out
     */
    public function create(
        Message $message,
        ComposeContext $ctx,
        ?User $user,
        array $groups = ['Default'],
    ): FormInterface {
        $options = [
            'user'              => $user,
            'validation_groups' => $groups,
        ];

        $form = false === $ctx->inline
            ? $this->forms->create(ComposeType::class, $message, $options)
            : $this->forms->createNamed(ComposeContext::INLINE_FORM, ComposeType::class, $message, $options);

        // The stored body references its inline images as `cid:`, which is what
        // has to go on the wire and what no browser can render. The editor gets
        // them back as attachment URLs; a submit overwrites this with what the
        // user actually typed, and DraftPersister turns it back.
        $form->get('bodyHtml')->setData(
            $this->inlineImages->toDisplay($message->bodyHtml, $message),
        );

        return $form;
    }
}
