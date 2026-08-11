<?php

declare(strict_types=1);

namespace App\Service\Mail;

enum SenderMismatchKind: string
{
    /**
     * The display name spells out a domain, and it is not the sender's.
     *
     *   "service@paypal.com" <billing@sendgrid-bounce.example>
     *          ^ claimed                    ^ actual
     */
    case DomainInName = 'domain_in_name';

    /**
     * The display name names an ORGANISATION — it carries a legal form such as
     * GmbH, Inc or Ltd — and none of the words in it appear in the sender's
     * registrable domain.
     *
     *   "Hetzner Online GmbH" <support@ownkhalsick.com>
     */
    case BrandInName = 'brand_in_name';

    public function translationKey(): string
    {
        return 'message.security.mismatch.' . $this->value;
    }
}
