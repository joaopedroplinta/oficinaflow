<?php

namespace App\Support;

final class Money
{
    /** Centavos inteiros → "R$ 1.234,56". Dinheiro nunca é float fora daqui (só na exibição). */
    public static function brl(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'R$ '.number_format(abs($cents) / 100, 2, ',', '.');
    }
}
