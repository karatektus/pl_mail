**An S/MIME certificate in an old-style `.p12` file can be imported.** Files locked with 3DES, RC2
or RC4 — what Windows' certificate export writes by default, and what some free S/MIME certificates
come as — were refused with a request to export them again with AES. plMail now opens them in the
browser, as it does AES files; what it exports is still AES. No schema change.
[#372](https://github.com/karatektus/pl_mail/issues/372), by @pmdevelopment.
