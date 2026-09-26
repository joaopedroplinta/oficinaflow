<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Cadastro: desativa-se, não se apaga (há histórico de entradas apontando para ele).
#[Fillable(['name', 'contact', 'notes', 'active'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
