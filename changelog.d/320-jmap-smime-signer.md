**The phone apps can have an S/MIME signature checked by the server, and keep the sender's
certificate.** Reading S/MIME-encrypted mail in an app, a signature inside it was judged on the
device alone, without the server's trusted authorities, and the sender's certificate was never kept,
so somebody you read only on the phone never became reachable encrypted. The new JMAP method
`ContactKey/smimeSigner` does what the web app does: it says whether the signer is vouched for,
untrusted, changed or invalid, and keeps the certificate for the sender. No schema change.
[#320](https://github.com/karatektus/pl_mail/issues/320).
