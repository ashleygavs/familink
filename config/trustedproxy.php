<?php

/*
| The proxies whose X-Forwarded-* headers the app believes. Anything else is ignored, so a visitor cannot pretend to be
| another address to dodge the login lockout. A tunnel or a reverse proxy running on the same computer is covered by the
| default. Behind a host's load balancer, set TRUSTED_PROXIES to the balancer's addresses, separated by commas. Use *
| only when the app can be reached through the balancer alone.
*/

return [
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1') === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
];
