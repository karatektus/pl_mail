<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\TrustedImageSender;

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
        /**
         * The allowlist entry that trusts the sender — their address, a
         * pattern such as `invoice+*@stripe.com`, or `*@domain` (#807) — or
         * null when none does.
         */
        public readonly ?string          $trustedBy,
        public readonly bool             $inSpam,
        public readonly ?SenderMismatch  $mismatch,
        /** The `sandbox` attribute value for the reading frame. */
        public readonly string           $sandbox,
        /** The frame's own Content-Security-Policy, for its <meta>. */
        public readonly string           $csp,
        /**
         * The frame's own height-and-hover script, embedded verbatim.
         *
         * Authorised by its HASH in both policies rather than by a nonce — see
         * MessageFrameScript for why a nonce cannot work here at all.
         */
        public readonly string           $frameScript,
        /** Plain-text fallback, when the message has no HTML body. */
        public readonly ?string          $text,
        /**
         * The sender's domain, when the images bar may offer to trust all of
         * it (#807); null when it may not. See MessageRenderer::trustableDomain().
         */
        public readonly ?string          $trustableDomain = null,
    ) {
    }

    /**
     * Whether to offer the "Show images" bar: there is something to show, and
     * it is not already showing.
     */
    public bool $offersImages {
        get => false === $this->imagesAllowed && $this->content->blocked > 0;
    }

    /** Whether the sender is on the user's allowlist, by address or by domain. */
    public bool $senderTrusted {
        get => null !== $this->trustedBy;
    }

    /** Whether that entry is a whole domain, for the line under the message. */
    public bool $trustedByDomain {
        get => null !== $this->trustedBy && str_starts_with($this->trustedBy, TrustedImageSender::DOMAIN_PREFIX);
    }

    /** Whether that entry is a pattern, which the line names as it was written. */
    public bool $trustedByPattern {
        get => null !== $this->trustedBy && TrustedImageSender::isPatternEntry($this->trustedBy);
    }

    /** The domain the entry covers, without its `*@`. */
    public ?string $trustedDomain {
        get => true === $this->trustedByDomain ? substr((string) $this->trustedBy, strlen(TrustedImageSender::DOMAIN_PREFIX)) : null;
    }

    public bool $hasWarning {
        get => $this->inSpam || null !== $this->mismatch;
    }
}
