<?php

return [
    // Prazo da garantia contado a partir da entrega; o valor é gravado em `warranty_until` no momento da entrega.
    'warranty_days' => (int) env('WARRANTY_DAYS', 90),
];
