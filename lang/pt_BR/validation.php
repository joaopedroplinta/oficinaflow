<?php

return [
    'array' => 'O campo :attribute deve ser uma lista.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'email' => 'Informe um e-mail válido em :attribute.',
    'exists' => 'O valor informado em :attribute não existe.',
    'in' => 'O valor selecionado em :attribute é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'max' => [
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'array' => 'O campo :attribute não pode ter mais de :max itens.',
    ],
    'min' => [
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
        'numeric' => 'O campo :attribute deve ser de pelo menos :min.',
        'array' => 'O campo :attribute deve ter pelo menos :min itens.',
    ],
    'regex' => 'O formato de :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser um texto.',
    'unique' => 'Já existe um registro com este valor em :attribute.',

    'attributes' => [
        'name' => 'nome',
        'phone' => 'telefone',
        'email' => 'e-mail',
        'password' => 'senha',
        'active' => 'ativo',
        'category' => 'categoria',
        'brand' => 'marca',
        'model' => 'modelo',
        'serial_number' => 'número de série',
        'q' => 'busca',
    ],
];
