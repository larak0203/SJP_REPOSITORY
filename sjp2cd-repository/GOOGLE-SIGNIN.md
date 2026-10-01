# Google Sign-In — setup

Google Sign-In is **built but switched off**. The button does not appear on the sign-in
page until you paste in a client id and secret, so an unconfigured install can never show
a control that does nothing.

---

## Read this first

**It needs the internet.** If the college wifi is down during your defence, Google
Sign-In will fail. Email and password remains the primary path and is never disabled —
that is deliberate. Do not plan a demonstration around the Google button.

**I could not test it.** Creating a Google Cloud project requires signing into a Google
account, which I will not do on your behalf. The code follows Google's documented OAuth
2.0 flow and verifies the token server-side, but it has never run against real
credentials. Test it yourself well before the defence, not on the day.

**Only you can complete this.** The steps below need your Google account.

---

## What it changes

This is the difference between the library vouching for someone and Google proving it.

| | Email and password | Google |
|---|---|---|
| Proves the address is really yours | No — a librarian confirms it by hand | **Yes** |
| A new college account | Waits for the library | **Opens immediately** |
| An account already waiting | Stays waiting | **Confirms itself on first sign-in** |
| An account the library closed | Refused | **Still refused** — Google proves who you are, not that you are welcome |
| Works with no internet | Yes | No |

The address is checked against `INSTITUTIONAL_DOMAINS`, the same list email and password
uses, so Google can never admit an address the other route would refuse.

---

## What you need to do

### 1. Create the OAuth client

1. Go to <https://console.cloud.google.com/>
2. Create a project — call it something like `SJP2CD Repository`
3. Open **APIs & Services → OAuth consent screen**
   - User type: **Internal** if `sjp2cd.edu.ph` is a Google Workspace domain,
     otherwise **External**
   - App name: `SJP2CD Institutional Repository`
   - Support email: your college address
4. Open **APIs & Services → Credentials → Create credentials → OAuth client ID**
   - Application type: **Web application**
   - Authorised JavaScript origins:

     ```
     http://localhost
     ```

   - Authorised redirect URI — exactly this, including the path:

     ```
     http://localhost/sjp2cd-repository/auth-google.php
     ```

     A trailing slash or a different port will be rejected by Google.
5. Copy the **Client ID** and **Client secret**

### 2. Put them in the config

Open `config/config.php` and fill in the two empty strings:

```php
define('GOOGLE_CLIENT_ID',     '1234567890-abcdef.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'GOCSPX-your-secret-here');
define('GOOGLE_HOSTED_DOMAIN', 'sjp2cd.edu.ph');
```

`GOOGLE_ENABLED` becomes true on its own once both are non-empty. The button appears on
the sign-in page immediately — no other change is needed.

### 3. Run the readiness check

```
C:\xampp\php\php.exe C:\xampp\htdocs\sjp2cd-repository\sql\check-google.php
```

It checks the credentials, that the hosted domain matches the college domain list, and
that this machine can reach Google over HTTPS with certificate verification on. It also
prints the exact redirect URI to paste into the console — a mismatch there is the usual
cause of `redirect_uri_mismatch`.

On this machine the connection has already been checked and works, so if nothing else is
wrong you should see two ticks and the URI.

### 4. Test it, both ways

Sign in with a real college account: you should land on the dashboard with the account
already open, and the library gets a notification saying it confirmed itself.

Then try a personal Gmail account on the same button. It must be refused with *"Only
college accounts can sign in with Google."* If that one fails, stop and tell me.

### Also worth knowing: cURL and certificates

XAMPP on Windows sometimes ships without a CA bundle, which makes every HTTPS request
fail. Test it:

```bash
C:/xampp/php/php.exe -r "var_dump(curl_version()['ssl_version']);"
```

If sign-in later fails with an SSL error, download `cacert.pem` from
<https://curl.se/docs/caextract.html>, save it in `C:\xampp\php\extras\ssl\`, and set
`curl.cainfo` in `php.ini` to point at it.

---

## What happens when someone signs in

1. They press **Continue with your sjp2cd.edu.ph account**
2. Google asks which account, then returns to `auth-google.php` with a code
3. The code is exchanged for a token; the token is **verified with Google**, not decoded
   locally, because this install has no JWT library and an unverified token is worthless
4. Three things are checked on the server:
   - the token was issued for **this** client id
   - the email address is **verified**
   - the account belongs to **sjp2cd.edu.ph** — checked against the verified `hd` claim,
     not the `hd` request parameter, which any caller can change
5. Known address → signed in. Unknown address → a **student** account is created with an
   unusable random password, and every library account is notified

A `state` token guards the round trip against cross-site request forgery.

---

## Security notes

- **Never commit the client secret.** If this project goes into git, put
  `config/config.php` in `.gitignore` first, or keep the secret in an environment
  variable.
- Accounts created through Google get the **student** role. Promote to faculty or library
  staff from **People** in the admin area.
- An account created this way has no working password. If someone needs email sign-in
  too, the library sets a password from the People page.
- Deactivated accounts are refused even with a valid Google session.

---

## If you decide not to use it

Leave `GOOGLE_CLIENT_ID` empty. The button never renders, `auth-google.php` redirects
anyone who reaches it back to the sign-in page with an explanation, and nothing else in
the system is affected. You can delete `auth-google.php` and this file if you would
rather it were not in your submission at all.
