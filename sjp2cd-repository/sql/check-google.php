<?php
/* ============================================================================
   Is Google Sign-In ready?

   Run this after pasting the client id and secret into config/config.php:

       C:\xampp\php\php.exe C:\xampp\htdocs\sjp2cd-repository\sql\check-google.php

   It checks the four things that actually stop this working, and prints the
   exact redirect URI to paste into the Google Cloud console -- getting that
   line wrong is the usual reason the button fails.
   ============================================================================ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

require __DIR__ . '/../config/config.php';

$ok = static fn(string $m) => print("  [ ok ] $m\n");
$no = static fn(string $m) => print("  [FAIL] $m\n");
$problems = 0;

echo "\nGoogle Sign-In readiness\n------------------------\n";

/* 1. credentials */
if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
    $no('No client id or secret in config/config.php. The button stays hidden until both are filled in.');
    $problems++;
} else {
    $ok('Client id and secret are set.');
    if (!str_ends_with(GOOGLE_CLIENT_ID, '.apps.googleusercontent.com')) {
        $no('That client id does not look like a Google one; they end in .apps.googleusercontent.com');
        $problems++;
    }
}

/* 2. the switch this drives */
GOOGLE_ENABLED ? $ok('GOOGLE_ENABLED is true, so the button appears on sign-in and sign-up.')
               : $no('GOOGLE_ENABLED is false.') && $problems++;

/* 3. the domain rule */
if (institutionalRoleFor('someone@' . GOOGLE_HOSTED_DOMAIN) === null) {
    $no('GOOGLE_HOSTED_DOMAIN is "' . GOOGLE_HOSTED_DOMAIN . '", which is not in INSTITUTIONAL_DOMAINS. '
      . 'Google would let someone in that email and password would refuse.');
    $problems++;
} else {
    $ok('Hosted domain "' . GOOGLE_HOSTED_DOMAIN . '" matches the college domain list.');
}

/* 4. can this machine talk to Google at all */
if (!function_exists('curl_init')) {
    $no('The cURL extension is not loaded, so the token exchange cannot happen.');
    $problems++;
} else {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=probe');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        $no('Cannot reach Google over HTTPS: ' . $err);
        echo "         If this mentions a certificate, download cacert.pem from https://curl.se/docs/caextract.html,\n";
        echo "         put it in C:\\xampp\\php\\extras\\ssl\\ and point curl.cainfo at it in php.ini.\n";
        $problems++;
    } else {
        $ok('Google is reachable over HTTPS with certificate verification on.');
    }
}

/* The line people get wrong */
$host = 'localhost';
echo "\nPaste this into the Google Cloud console, under Authorised redirect URIs,\n";
echo "exactly as printed, including http and the trailing path:\n\n";
echo "      http://$host" . url('auth-google.php') . "\n";
echo "\nAnd under Authorised JavaScript origins:\n\n      http://$host\n";

echo "\n" . ($problems === 0
    ? "Ready. Open the sign-in page and the college Google button will be there.\n\n"
    : $problems . " thing(s) to fix above.\n\n");

exit($problems === 0 ? 0 : 1);
