<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Everything a template needs to render one message body safely, decided once.
 *
 * Exists so that no Twig file has to ask a security question. A template that
 * can call `blocker.rewrite(...)` is a template that can forget to, and the
 * failure mode of forgetting is silent — the mail renders perfectly and the
 * tracking pixels fire.
 */
final class MessageRender
{
    /**
     * Readonly per property rather than on the class: the two virtual
     * properties below are hooks, and PHP forbids hooks on a readonly class.
     */
    public function __construct(
        /** The body, already blocked or already proxied. Safe to output raw. */
        public readonly RemoteContent    $content,
        /** Whether remote content was loaded (through the proxy) this render. */
        public readonly bool             $imagesAllowed,
        /** Whether that was because the sender is on the user's allowlist. */
        public readonly bool             $senderTrusted,
        public readonly bool             $inSpam,
        public readonly ?SenderMismatch  $mismatch,
        /** The `sandbox` attribute value for the reading frame. */
        public readonly string           $sandbox,
        /** The frame's own Content-Security-Policy, for its <meta>. */
        public readonly string           $csp,
        /** Nonce permitting the frame's own height/hover script, and nothing else. */
        public readonly string           $nonce,
        /** Plain-text fallback, when the message has no HTML body. */
        public readonly ?string          $text,
    ) {
    }

    /**
     * Whether to offer the "Show images" bar: there is something to show, and
     * it is not already showing.
     */
    public bool $offersImages {
        get => false === $this->imagesAllowed && $this->content->blocked > 0;
    }

    public bool $hasWarning {
        get => $this->inSpam || null !== $this->mismatch;
    }
}
