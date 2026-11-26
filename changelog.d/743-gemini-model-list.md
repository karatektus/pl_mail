**Testing a Gemini model host no longer calls its models missing.** Gemini's OpenAI-compatible model
list spells each model with a `models/` prefix, which the Test button on Admin → AI compared
literally, so it warned about models that then worked. That prefix is now ignored. A model that
really is not on an OpenAI-compatible host's list is reported with a hint to check the name against
the list, instead of telling you to pull it onto the host, which only applies to Ollama.
No schema change. [#743](https://github.com/karatektus/pl_mail/issues/743), reported by @llaumgui.
