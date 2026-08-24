<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The height-and-hover reporter that runs inside the message frame, and the
 * CSP hash that authorises it.
 *
 * ── Why a hash and not a nonce ───────────────────────────────────────────────
 *
 * A `srcdoc` frame inherits the EMBEDDING page's Content-Security-Policy on top
 * of the <meta> one it carries itself, so this script has to satisfy both. A
 * nonce cannot do that. The page's nonce is fixed when the page loads, and the
 * message is very often rendered by a LATER request — clicking a conversation
 * fetches it into a Turbo Frame — which has a nonce of its own. The two only
 * ever coincide on a full page load, which is exactly the shape the bug had:
 * the frame measured itself correctly after a reload and never on the first
 * open.
 *
 * A hash has no such problem. It is a property of the script text, identical in
 * every request, so the page's policy and the frame's own policy can both name
 * it without either knowing when the other was rendered.
 *
 * It is also strictly tighter than the nonce it replaces. A nonce authorises
 * ANY script carrying it; a hash authorises one exact script and nothing else.
 *
 * ── Why the script lives in a file ───────────────────────────────────────────
 *
 * So the hash is DERIVED rather than maintained. A hash written down beside the
 * script is a hash that drifts the first time somebody edits the script, and
 * the failure is silent, remote and only in production — the policy is
 * Report-Only under debug, so every test would still pass while every real
 * message sat at its 80px floor. Reading the file and hashing what was read
 * makes disagreement impossible rather than merely unlikely.
 *
 * The file is plain JavaScript with no Twig in it, which is what makes any of
 * this work: a template interpolating so much as a colour would give every
 * message a different hash.
 */
final class MessageFrameScript
{
    private ?string $source = null;
    private ?string $hash   = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    /** The script itself, for embedding in the frame document. */
    public function source(): string
    {
        return $this->source ??= rtrim(
            (string) file_get_contents($this->path()),
            "\n",
        );
    }

    /**
     * The `sha256-…` source expression naming it.
     *
     * Base64 of the RAW digest, which is what the CSP specification asks for —
     * hex would be quietly wrong and would fail closed, in production only.
     */
    public function hash(): string
    {
        return $this->hash ??= 'sha256-' . base64_encode(hash('sha256', $this->source(), true));
    }

    private function path(): string
    {
        return $this->projectDir . '/templates/mail/_frame_script.js';
    }
}
