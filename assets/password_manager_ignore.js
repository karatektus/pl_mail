// Tom Select (via Symfony UX Autocomplete) replaces the form field with its
// own visible <input>, so the PasswordManagerIgnore attributes a form type
// puts on the original element never reach the one extensions actually look
// at — and Bitwarden badges the compose To/Cc/Bcc boxes as logins. The bundle
// announces every instance it builds; decorate the control input then.
//
// Mirrors App\Form\PasswordManagerIgnore::ATTR — change both together.
const PASSWORD_MANAGER_IGNORE = {
    'data-bwignore':  'true',
    'data-1p-ignore': 'true',
    'data-lpignore':  'true',
    'data-form-type': 'other',
};

document.addEventListener('autocomplete:connect', (event) => {
    const input = event.detail?.tomSelect?.control_input;

    if (undefined === input || null === input) {
        return;
    }

    for (const [name, value] of Object.entries(PASSWORD_MANAGER_IGNORE)) {
        input.setAttribute(name, value);
    }
});
