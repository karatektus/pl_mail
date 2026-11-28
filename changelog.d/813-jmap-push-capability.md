**A JMAP client that declares every capability the server advertises is no longer refused.** The
Session listed `urn:plmail:params:jmap:push`, but a request naming it in `using` was turned down
whole with "Capability is not supported", so a client that copied the Session's capabilities could
not make a single call. It is accepted now, and a test holds every advertised capability to the list
a request may use. No schema change. [#813](https://github.com/karatektus/pl_mail/issues/813).
