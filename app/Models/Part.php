<?php

namespace App\Models;

use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Saldo (`on_hand`, `reserved_quantity`) fica fora: só muda por Action de estoque.
#[Fillable(['sku', 'name', 'sale_price_cents', 'active'])]
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory;

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
