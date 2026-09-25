<?php

// Sem proxy confiável por padrão: o X-Forwarded-For é ignorado e o IP do login não pode ser forjado.
// Atrás de um proxy/balanceador, TRUSTED_PROXIES lista os IPs/CIDRs dele, separados por vírgula
// ("*" só se o proxy sobrescrever o cabeçalho). Vem de config (e não de env() no bootstrap) para valer
// com o .env e com config:cache. O TrustProxies do Laravel lê `trustedproxy.proxies` a cada requisição.
return [
    'proxies' => env('TRUSTED_PROXIES'),
];
