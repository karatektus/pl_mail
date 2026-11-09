**Copies of one email stored before v0.4.1 are folded even when an insight card links to one.**
Folding the copies Proton's All Mail and similar folders had left as separate messages failed
whenever the copies were in two conversations and one of them had an insight card, such as a parcel
or a flight: the fold was rolled back with a Doctrine error on every sweep, and that email stayed
doubled. It is folded now, and the card links to the message that stays. No schema change.
[#521](https://github.com/karatektus/pl_mail/issues/521), reported by @sponger544.
