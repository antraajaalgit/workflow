<?php

return [
    // Exact infrastructure IPs only; never trust arbitrary peers.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXY_IPS', ''))),
        fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)),
];
