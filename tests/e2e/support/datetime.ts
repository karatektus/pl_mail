import type { Locator } from "@playwright/test";

/**
 * Drive a field that ui--datetime has taken over, the way a person does.
 *
 * The native `datetime-local` / `time` input is still in the page — hidden,
 * named, carrying the ISO value the form posts — but nobody can type into it;
 * the date field and the time text field drawn after it are what is on screen.
 * So a spec hands over the hidden input it always had a locator for, and this
 * fills the visible pair beside it. The time is typed as "HH:MM", which the
 * field reads on either clock setting.
 *
 * Assertions on the hidden input itself — its value, aria-invalid — stay as
 * they were: that is the contract the rest of the page reads.
 */
export function visibleField(field: Locator): Locator {
    return field.locator("xpath=following-sibling::*[@data-datetime-field][1]");
}

export async function fillDateTime(field: Locator, value: string): Promise<void> {
    const box = visibleField(field);
    const [date, time] = value.includes("T") ? value.split("T") : [null, value];

    if (null !== date) {
        await box.locator('input[type="date"]').fill(date);
    }

    const timeField = box.locator('input[type="text"]');
    await timeField.fill(time);
    await timeField.press("Tab");
}
