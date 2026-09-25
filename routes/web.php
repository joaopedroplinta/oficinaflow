<?php

use Illuminate\Support\Facades\Route;

// SPA React: qualquer rota fora de /api cai no mesmo shell.
Route::view('/{any?}', 'app')->where('any', '^(?!api|up).*$');
