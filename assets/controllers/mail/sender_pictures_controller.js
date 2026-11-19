import { Controller } from "@hotwired/stimulus";

/**
 * Puts a sender picture a background lookup just found onto the avatars
 * already on screen (#639).
 *
 * Mounted on <body> by the mailbox layout, beside core--mercure, which
 * dispatches `sender-picture-found` with what SenderPictureNotifier sent:
 *
 *   kind  — "bimi" | "hashed" | "website"
 *   key   — which avatars: an address hash for "hashed", the From domain for
 *           "bimi", the registrable domain for "website"
 *   rank  — SenderPictureKind::rank(); lower wins
 *   url   — the picture
 *
 * Each avatar carries the same keys (templates/_partials/_sender_picture
 * .html.twig), whether it passed the sender check, and the rank of what it
 * shows now — 99 for initials. A picture replaces another only if it ranks
 * better, so a website icon arriving late never covers a contact's photo,
 * and a BIMI logo never lands on a message that failed the check.
 *
 * Values, targets: none. Actions:
 *   found — core--mercure:sender-picture-found
 */
export default class extends Controller {
    found(event) {
        const { kind, key, rank, url } = event.detail ?? {};

        if ("string" !== typeof key || "" === key || "string" !== typeof url || !url.startsWith("/")) {
            return;
        }

        const attribute = {
            hashed:  "data-sender-picture-address",
            bimi:    "data-sender-picture-domain",
            website: "data-sender-picture-site",
        }[kind];

        if (undefined === attribute) {
            return;
        }

        document.querySelectorAll(`[${attribute}="${CSS.escape(key)}"]`).forEach((avatar) => {
            if ("bimi" === kind && "1" !== avatar.dataset.senderVerified) {
                return;
            }

            if (Number(avatar.dataset.senderPictureRank ?? 99) <= Number(rank)) {
                return;
            }

            this.#show(avatar, url, Number(rank));
        });
    }

    // ── Private ───────────────────────────────────────────────────────────

    #show(avatar, url, rank) {
        let image = avatar.querySelector("[data-sender-picture-image]");

        if (null === image) {
            // The same element the template draws — see image() in
            // _partials/_sender_picture.html.twig — built here rather than
            // fetched, because it is an <img> and a class list.
            image = document.createElement("img");
            image.alt = "";
            image.decoding = "async";
            image.draggable = false;
            image.dataset.senderPictureImage = "";
            image.className = "absolute inset-0 h-full w-full rounded-full object-cover bg-surface";
            avatar.append(image);
        }

        image.src = url;
        avatar.dataset.senderPictureRank = String(rank);
    }
}
