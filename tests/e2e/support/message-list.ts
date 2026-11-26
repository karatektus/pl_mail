import { expect, type Page } from "@playwright/test";
import { ajaxPost } from "./config";

/**
 * The message list settings (#608), for specs that need the list in a given
 * shape without driving Settings → Inbox → Message list to get it.
 *
 * Two ways in, for two kinds of spec. `saveListSettings` stores them on the
 * user through the page's own endpoint, so a reload, a second tab and a list
 * page drawn by the server all see them. `paintListSettings` only writes the
 * attributes on this page's <html>, which is all app.css reads, for a spec
 * measuring geometry that must not depend on what the shared seed user last
 * saved — and must not leave anything saved behind.
 */

/** Every setting at the value a fresh user has; what a spec restores. */
export const LIST_DEFAULTS = {
    layout: "fit",
    preview: "one",
    attachments: "icon",
    labels: "line",
    sender: "everyone",
    date: "smart",
    messageCount: true,
    replied: false,
    star: "hover",
    avatars: true,
    accountCorner: true,
    unreadEmphasis: "standard",
    tabUnreadCount: true,
} as const;

export async function saveListSettings(page: Page, settings: Partial<Record<keyof typeof LIST_DEFAULTS, string | boolean>>): Promise<void> {
    const response = await ajaxPost(page, "/settings/message-list/save", settings);

    expect(response.ok()).toBe(true);
}

export async function resetListSettings(page: Page): Promise<void> {
    await saveListSettings(page, LIST_DEFAULTS);
}

/** `layout` and `preview` as the layout writes them, on this page only. */
export async function paintListSettings(page: Page, attributes: { layout?: string; preview?: string }): Promise<void> {
    await page.evaluate((attributes) => {
        const root = document.documentElement;

        if (attributes.layout) {
            root.setAttribute("data-list-layout", attributes.layout);
        }

        if (attributes.preview) {
            root.setAttribute("data-list-preview", attributes.preview);
        }
    }, attributes);
}
