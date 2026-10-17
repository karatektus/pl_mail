/**
 * Asks for the secret that opens the keyring, in the one dialog every caller
 * shares — see templates/crypto/_unlock_dialog.html.twig for when it appears,
 * and Keyring.requireOpen() for the only caller.
 *
 * The dialog tries the secret itself and stays open on a wrong one, so a typo
 * costs a second attempt rather than a message going back to "locked". It
 * answers true once the keyring is open, false when the person cancels — with
 * the button, Escape, or by the page going away underneath it.
 *
 * Not a Stimulus controller: it is asked for from inside the keyring, by code
 * that has to wait for the answer, and a controller has no way to be awaited.
 */

/**
 * @param {import("./keyring.js").Keyring} ring
 * @returns {Promise<boolean>}
 */
export function askToUnlock(ring) {
    const dialog = document.getElementById("keyring-unlock-dialog");

    // Pages without the keyring boot (the forced password change, the
    // sharing pages) have no dialog, and nothing on them needs one.
    if (!(dialog instanceof HTMLDialogElement) || true === dialog.open) {
        return Promise.resolve(false);
    }

    const form = dialog.querySelector("[data-keyring-unlock-form]");
    const input = dialog.querySelector("[data-keyring-unlock-secret]");
    const error = dialog.querySelector("[data-keyring-unlock-error]");
    const submit = dialog.querySelector("[data-keyring-unlock-submit]");
    const cancel = dialog.querySelector("[data-keyring-unlock-cancel]");
    const root = ring.root;

    dialog.querySelectorAll("[data-root]").forEach((element) => {
        element.hidden = root !== element.dataset.root;
    });
    // A password manager offers the sign-in password for "current-password";
    // the passphrase is a different secret and must not be filled with it.
    input.autocomplete = "passphrase" === root ? "off" : "current-password";
    input.value = "";
    error.hidden = true;

    return new Promise((resolve) => {
        let settled = false;

        const finish = (opened) => {
            if (true === settled) {
                return;
            }

            settled = true;
            form.removeEventListener("submit", onSubmit);
            cancel.removeEventListener("click", onCancel);
            dialog.removeEventListener("close", onClose);
            input.value = "";

            if (true === dialog.open) {
                dialog.close();
            }

            resolve(opened);
        };

        const onSubmit = async (event) => {
            event.preventDefault();

            if ("" === input.value) {
                input.focus();

                return;
            }

            submit.disabled = true;

            try {
                const result = await ring.unlock(input.value);

                finish("open" === result.status);
            } catch (failure) {
                const reason = {
                    wrong_password: error.dataset.wrongPassword,
                    wrong_passphrase: error.dataset.wrongPassphrase,
                    rate_limited: error.dataset.rateLimited,
                }[failure?.message] ?? error.dataset.failed;

                error.textContent = reason;
                error.hidden = false;
                input.select();
            } finally {
                submit.disabled = false;
            }
        };

        const onCancel = () => finish(false);
        const onClose = () => finish(false);

        form.addEventListener("submit", onSubmit);
        cancel.addEventListener("click", onCancel);
        // Escape closes a modal <dialog> by itself; this is how that is heard.
        dialog.addEventListener("close", onClose);

        dialog.showModal();
        input.focus();
    });
}
