<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The floating surfaces get an opacity of their own.
 *
 * One slider used to set the opacity of everything: the sidebar and the top
 * bar, and also the compose window, the modals and the toasts stacked on top
 * of them. Those are not the same problem. A pane has nothing behind it but
 * the wallpaper; a composer has the wallpaper AND a translucent pane, and the
 * two multiply — at the old shared 0.7 a tenth of the picture came through
 * both layers and landed in the middle of what somebody was typing.
 *
 * WHY EVERY EXISTING ROW GETS 1.0 RATHER THAN ITS OLD PANE VALUE
 * ──────────────────────────────────────────────────────────────
 * The old value stays exactly where it was — appearance_pane_alpha is not
 * touched, so nobody's sidebar, top bar, main pane or calendar moves by a
 * pixel. What does NOT carry forward is that value's second job.
 *
 * Copying it into the new column would have preserved the bug and broken
 * something else at the same time. Of the fifteen floating surfaces, eleven
 * are dropdowns and menus, and those have never honoured the old slider at
 * all: `@utility popover` in app.css has been hardcoded fully opaque since it
 * was written, precisely because a see-through menu is unreadable over the
 * toolbar it covers. Backfilling 0.7 there would have introduced that fault
 * in eleven places to preserve it in four.
 *
 * So 1.0 is the value that leaves the majority of these surfaces looking
 * exactly as they did, and moves the remaining four — compose window, modal,
 * confirm dialog, toast — to opaque. That is a visible change only for
 * accounts running glass, which is to say for exactly the accounts the
 * complaint came from, and it is one slider away from being undone: the new
 * control sits directly under the old one in Settings → Appearance → Glass.
 *
 * DEFAULT 1, matching Appearance::$popoverAlpha and Layout::Flat's preset. The
 * boxed layout seeds 0.9 rather than its panes' 0.7, so picking a layout still
 * hands out glass — just not two layers of it over the same words.
 */
final class Version20260826230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.appearance_popover_alpha; the composer and modals stop sharing the panes opacity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_popover_alpha DOUBLE PRECISION DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_popover_alpha');
    }
}
