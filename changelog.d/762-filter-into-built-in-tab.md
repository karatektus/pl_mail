**A filter can file mail into a built-in tab.** **File in category** now lists Updates, Promotions,
Social, Forums and Primary beside your own tabs, so "everything from amazon.fr goes to Updates" is
one filter. The choice holds when mail is sorted again; a tab you switched off is not offered, and
mail filed into one lands in Primary. Choosing **Move to folder** for an action no longer leaves
the editor without its folder list. [#762](https://github.com/karatektus/pl_mail/issues/762), by
@jo2904.

**Migration:** adds the nullable `message.rule_builtin_category` column. No deployment change.
