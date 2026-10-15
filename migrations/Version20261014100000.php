<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which Web Push rows want new-mail pushes only: jmap_push_subscription gains
 * arrivals_only, see App\Entity\User\PushSubscription::$arrivalsOnly.
 *
 * The column is NOT NULL with a default of false, in the schema as well as in
 * the entity, so every row that exists is a full-stream row until it is told
 * otherwise. That is what every row was before this feature.
 *
 * The backfill is the one line that is not schema. The in-app toggle
 * (assets/controllers/settings/web_push_controller.js, _deviceClientId()) mints
 * its device ids as `web-<uuid>`, which is the only thing that identifies a
 * browser/PWA row today: nothing else records that the row came from the app's
 * own registration rather than from a JMAP client. Without the backfill, a
 * device that registered before this change would keep receiving every
 * StateChange until somebody switched notifications off and on, and the one
 * device this feature exists for would look unfixed. The UPDATE narrows those
 * rows once, here, and the device does not have to do anything.
 *
 * It is a one-time migration, not a rule. Dispatch never matches on the `web-`
 * prefix; it reads the flag. A row created after this runs gets the flag from
 * WebPushController, not from its id, so the prefix is not something the code
 * may come to depend on.
 *
 * Reversible. Going down drops the column, which returns every row to the full
 * stream; a device that was narrowed registers again to be narrowed, and there
 * is no other side effect.
 */
final class Version20261014100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Narrow in-app Web Push devices to new-mail pushes: jmap_push_subscription.arrivals_only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jmap_push_subscription ADD arrivals_only BOOLEAN DEFAULT false NOT NULL');

        $this->addSql("UPDATE jmap_push_subscription SET arrivals_only = TRUE WHERE transport = 'webpush' AND device_client_id LIKE 'web-%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jmap_push_subscription DROP arrivals_only');
    }
}
