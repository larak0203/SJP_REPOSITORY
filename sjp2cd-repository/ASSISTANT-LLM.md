# Turning on the conversational assistant

The assistant works **right now with no setup** — it answers from your database and a
curated set of notes about how this system works, needs no internet, and costs nothing.

This file is only for the optional extra: letting it hold a general conversation about
anything, using a language model.

---

## Read this before you decide

**It needs the internet.** If the college wifi is down during your defence, the model
cannot be reached. The assistant still works — it falls back to the grounded answers —
but the "ask me anything" part goes quiet.

**It costs money.** Every message is billed to your account. The default model
(`gpt-4o-mini`) is among the cheapest; a demonstration would cost cents, not pesos of
consequence. But a key left in a system exposed to the internet can be abused, so keep
this local.

**Your key would sit in your source code.** If this project goes into git or is handed
in as a folder, the key goes with it. Anyone who has it can spend your money.

**I have not tested it.** The integration follows the documented API, but it has never
run against a real key — I will not create an account or handle a credential on your
behalf. Test it yourself well before the defence.

---

## What the model is and is not allowed to do

The ordering matters, and it is deliberate:

1. Small talk, greetings and thanks → answered locally
2. Anything about **this repository** — holdings, deposits, standards, workflow,
   preservation → answered from **your database and curated notes**
3. **Only what is left over** reaches the language model

So a question like "how many records do you have?" never touches the model. It cannot
invent a number, a record title or a feature, because it is never asked. On top of that,
its instructions explicitly forbid it from making claims about how the repository works.

Any answer that did come from the model is **labelled as such** in the chat, so a reader
always knows which they are looking at.

---

## Switching it on

1. Create an API key at <https://platform.openai.com/api-keys>
2. Open `config/config.php` and fill in the key:

```php
define('ASSISTANT_LLM_KEY',   'sk-your-key-here');
define('ASSISTANT_LLM_MODEL', 'gpt-4o-mini');
```

`ASSISTANT_LLM_ON` becomes true by itself. Nothing else changes.

3. Check cURL can make HTTPS requests. XAMPP on Windows sometimes ships without a
   certificate bundle, which makes every call fail silently:

```bash
C:/xampp/php/php.exe -r "var_dump(curl_version()['ssl_version']);"
```

If calls fail, download `cacert.pem` from <https://curl.se/docs/caextract.html>, put it in
`C:\xampp\php\extras\ssl\`, and point `curl.cainfo` at it in `php.ini`.

### Using a different provider

Any OpenAI-compatible endpoint works — change `ASSISTANT_LLM_URL` and
`ASSISTANT_LLM_MODEL`. The request shape is the standard chat-completions format.

---

## Switching it off

Empty the key. The assistant returns to grounded-only answers immediately, and an
unrecognised question gets an honest "I don't know" with links to the pages that might
help. Nothing else in the system is affected.

---

## My recommendation

**Leave it off for the defence.** The grounded assistant already answers everything a
panel is likely to ask about your system, and it answers from live data — which is the
more impressive thing to demonstrate. It also cannot embarrass you by confidently
inventing a feature.

Turn the model on afterwards if you want the general-conversation behaviour, once nothing
depends on it working.
