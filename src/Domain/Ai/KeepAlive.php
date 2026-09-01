<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * How long an Ollama host is asked to keep a model in memory after a request.
 *
 * WHY THIS IS CONFIGURED HERE AT ALL
 * ──────────────────────────────────
 * Ollama's own default lives in OLLAMA_KEEP_ALIVE, an environment variable on
 * the model host — a machine plMail has no way to reach and no business
 * editing. The API takes the same setting per request, and a per-request value
 * overrides the host's, so this is the only lever an administrator has from
 * inside the application. It is worth having: the writing model is around
 * 18 GB of weights, and the difference between resident and not is the whole
 * of the wait somebody feels on the first "Help me write" after lunch.
 *
 * THE THREE SPELLINGS ARE NOT INTERCHANGEABLE ON THE WIRE
 * ──────────────────────────────────────────────────────
 * This is the part that is easy to get wrong and impossible to see afterwards.
 * Ollama decodes `keep_alive` from either a JSON NUMBER, which it reads as
 * seconds, or a JSON STRING, which it parses as a Go duration — and a Go
 * duration must carry a unit. So `"-1"` and `"3600"` sent as strings are not
 * "the same value typed differently": they fail to parse, and the host answers
 * 400 for a setting that reads as perfectly correct in the admin form.
 *
 * {@see forBody()} is therefore the only thing that should ever put this value
 * into a request. It answers an int for the numeric spellings and a string for
 * the duration ones, and null for "we were not asked to say anything" — which
 * is a real, selectable state and not an absence to be filled in with a
 * default. An empty box means the host's own OLLAMA_KEEP_ALIVE stands, and an
 * operator who has set that up deliberately must be able to say so.
 *
 * A class of statics rather than an instance, for PromptRules' reason: nothing
 * here has state, every reader wants the literal value, and the two readers —
 * a form constraint and a request body — want different halves of it.
 */
final class KeepAlive
{
    /**
     * What the writing model gets on an installation that has never said.
     *
     * Ollama's own default is five minutes, so this changes nothing about how
     * an unconfigured install behaves — it is here so that the field the
     * administrator opens SHOWS the number in force rather than an empty box
     * whose meaning they would have to know Ollama to guess.
     */
    public const string DEFAULT_CHAT = '5m';

    /**
     * And what the search model gets. The same answer, for a different reason.
     *
     * `-1` — pinned indefinitely — is the better setting on most installations
     * and it is NOT what ships. An embedding model is a few hundred megabytes
     * against the writing model's eighteen gigabytes and it is asked for on the
     * interactive path constantly, so paying a cold load for it is close to the
     * worst trade available, and the handbook says so.
     *
     * What plMail cannot see is how much memory that host has or what else is
     * on it, and `-1` is the one value here that never gives memory back.
     * Choosing it on an operator's behalf is choosing it with less information
     * than they have — the same reason nothing in AiSettings switches itself
     * on. So the neutral value ships, the recommendation is written down, and
     * the change is one keystroke on a page they are already looking at.
     *
     * ITS OWN CONSTANT DESPITE MATCHING THE ONE ABOVE. They are two independent
     * settings that happen to agree today, and one constant read by both fields
     * would make a future change to either of them silently change the other.
     */
    public const string DEFAULT_EMBEDDING = '5m';

    /**
     * The accepted spellings, as one expression, because the form and this
     * class must not be able to disagree about them.
     *
     *  · `-1`      — keep it loaded until something else evicts it
     *  · `0`       — unload the moment the request finishes
     *  · `3600`    — a whole number of seconds
     *  · `30m`     — a positive number with a unit of s, m or h
     *
     * Lower case only, and deliberately: Go's duration parser does not accept
     * `5M`, so accepting it here would mean either sending a value the host
     * refuses or silently rewriting what somebody typed. Refusing it says so at
     * the moment they can still fix it.
     */
    public const string PATTERN = '~^(?:-1|\d+|[1-9]\d*[smh])$~';

    /**
     * The value as it goes into a request body, or null to send no field.
     *
     * Null for empty, and for anything that does not match {@see PATTERN}. The
     * second half is a belt-and-braces answer to a value that reached the
     * column without passing the form — a hand-edited row, a restored backup
     * from a future version — and the safe reading of a value we do not
     * understand is to say nothing and let the host's own default stand, rather
     * than to send it on and have every model call fail at once.
     */
    public static function forBody(?string $value): int|string|null
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        if ('' === $trimmed) {
            return null;
        }

        if (1 !== preg_match(self::PATTERN, $trimmed)) {
            return null;
        }

        // A number stays a number. See the class docblock: the string "3600"
        // has no unit, so Ollama's duration parser refuses it.
        if (1 === preg_match('~^-?\d+$~', $trimmed)) {
            return (int) $trimmed;
        }

        return $trimmed;
    }

    /**
     * '' rather than null for a box somebody emptied.
     *
     * Doctrine happily stores the empty string, and a column holding '' and one
     * holding NULL would mean the same thing while comparing differently —
     * exactly the kind of second state that makes a later `IS NULL` read wrong.
     * One spelling for "nothing is set", chosen at the edge where the value
     * arrives.
     */
    public static function normalised(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        if ('' === $trimmed) {
            return null;
        }

        return $trimmed;
    }
}
