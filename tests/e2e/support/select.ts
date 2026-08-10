import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Pick an option from a select the way a person does.
 *
 * Every user-facing single select in the app is progressively enhanced into a
 * Tom Select by assets/controllers/ui/select_controller.js. The native
 * `<select>` stays in the DOM as the source of truth — it is what the form
 * posts and what `change` fires on — but it is no longer the element the
 * `<label>` points at: Tom Select moves the label's `for` onto the widget it
 * builds and gives that widget `role="combobox"`, `aria-labelledby`,
 * `aria-expanded`, `aria-controls` and a `role="listbox"` panel of
 * `role="option"` rows.
 *
 * So `getByLabel("Repeat")` resolves to the combobox, not to the select, and
 * `selectOption()` on it fails with "Element is not a <select> element". That
 * is not a broken label — the label reaches an operable control, which is what
 * a screen reader needs — it is the old native-select gesture aimed at a widget
 * that no longer answers to it. Ten calendar specs died that way between
 * v0.0.22 and v0.0.24.
 *
 * Driving it through the ARIA contract rather than through `.ts-*` class names
 * is deliberate: the assertions below are the contract a screen-reader user
 * depends on, so this helper fails if the widget ever stops being a labelled,
 * expandable combobox over a list of named options — the exact regression the
 * theming change could have introduced and did not.
 *
 * Still one call for the caller either way. With JS off, or on the handful of
 * selects left native on purpose (compose's address fields, the provider
 * preset), the label still names a real `<select>` and this falls through to
 * `selectOption`.
 *
 * @param scope  the page, or the region the field lives in (usually the modal)
 * @param label  the field's visible label, as `getByLabel` sees it
 * @param option the option's visible text — what a user reads, not its value
 */
export async function choose(scope: Page | Locator, label: string, option: string): Promise<void> {
    const control = scope.getByLabel(label, { exact: true });
    const page = control.page();

    // Not enhanced: still a real select, so the native gesture is the honest one.
    if (await control.evaluate((el) => el instanceof HTMLSelectElement)) {
        await control.selectOption({ label: option });

        return;
    }

    // Enhanced. The label has to have reached a combobox that says where its
    // list is; without that, no assistive technology could open this either.
    await expect(control).toHaveAttribute("role", "combobox");
    const listboxId = await control.getAttribute("aria-controls");
    expect(listboxId).toBeTruthy();

    // Focusing the control opens the panel on its own, and a click on an open
    // panel is the gesture that closes it again — so click only when it is shut.
    // Reached by a click on a *focused* control in practice: the label's own
    // click handler focuses the widget, which is why this is not hypothetical.
    if ((await control.getAttribute("aria-expanded")) !== "true") {
        await control.click();
    }

    await expect(control).toHaveAttribute("aria-expanded", "true");

    await page.locator(`[id="${listboxId}"]`).getByRole("option", { name: option, exact: true }).click();

    // The widget shows what was chosen. Asserted rather than assumed because a
    // click that lands on a closing panel silently picks nothing, and the next
    // failure would then be the save, several steps away from its cause.
    await expect(control).toHaveText(option);
}
