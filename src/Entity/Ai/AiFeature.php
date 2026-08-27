<?php

declare(strict_types=1);

namespace App\Entity\Ai;

/**
 * The four things a model is allowed to be used for here.
 *
 * An enum rather than four booleans passed around, so that a caller cannot ask
 * "is AI on" in the abstract. It never is: it is on for a purpose, with a model
 * chosen for that purpose, and the answer differs per feature. See
 * AiSettings::enabledFor().
 */
enum AiFeature: string
{
    /** Semantic search — needs the EMBEDDING model. */
    case Search = 'search';

    /** Deciding which tab a message belongs in — needs the CHAT model. */
    case Categorise = 'categorise';

    /** Drafting help in the composer — needs the CHAT model. */
    case WritingHelp = 'writing_help';

    /**
     * Summarising an open conversation, on request — needs the CHAT model.
     *
     * ADDED LAST, AND THAT IS NOT AN ACCIDENT OF EDITING ORDER
     * ───────────────────────────────────────────────────────
     * AiPermissions::anyAdminEnabled() loops cases() and the settings
     * navigation calls it on EVERY settings section, so a case that exists
     * before its match arms do is an UnhandledMatchError on labels, profile and
     * security as well as on this feature's own page. The arms went in first;
     * this line is what switches them on. See AiCallFeature, whose docblock
     * predicted exactly this.
     */
    case Summary = 'summary';
}
