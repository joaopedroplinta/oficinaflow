<?php

return [
    // Prazo da garantia contado a partir da entrega; o valor é gravado em `warranty_until` no momento da entrega.
    'warranty_days' => max(0, (int) env('WARRANTY_DAYS', 90)),
];
