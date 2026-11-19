**A reply sent from a Google account stays in its conversation (#676).** plMail sent mail through
the Gmail API without saying which conversation it belonged to, so Gmail started a new one for every
reply, and the other side's answer followed it there. A three-message exchange became three
conversations, in plMail and in Gmail alike. Each reply was also listed twice: once where it was
written and once where Gmail filed its copy. Replies now go into the conversation they answer, and
Gmail's copy of mail sent from plMail is merged onto the message plMail already has. An encrypted
reply, whose subject goes out hidden as "...", is still filed in a new conversation by Gmail, which
groups mail by subject; plMail keeps it and the answers that follow it in the conversation it was
written in. Conversations that were split before stay split, as they are in Gmail; a reply listed
twice there can be deleted by hand. No schema change.
