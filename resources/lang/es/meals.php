<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meal Names
    |--------------------------------------------------------------------------
    */
    'meal_names' => [
        'desayuno' => 'Desayuno',
        'almuerzo' => 'Almuerzo',
        'cena' => 'Cena',
        'snack_am' => 'Snack AM',
        'snack_pm' => 'Snack PM',
    ],

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */
    'categories' => [
        'proteínas' => 'Proteínas',
        'carbohidratos' => 'Carbohidratos',
        'grasas' => 'Grasas',
        'vegetales' => 'Vegetales',
    ],

    /*
    |--------------------------------------------------------------------------
    | Personalized Messages
    |--------------------------------------------------------------------------
    */
    'personalized_message' => [
        'snack_am' => 'Hola :name, tu plan incluye 3 comidas principales (Desayuno, Almuerzo, Cena) y un snack en la media mañana, como prefieres.',
        'snack_pm' => 'Hola :name, tu plan incluye 3 comidas principales (Desayuno, Almuerzo, Cena) y un snack en la media tarde, como prefieres.',
        'default' => 'Hola :name, tu plan incluye 3 comidas principales (Desayuno, Almuerzo, Cena) y un snack en la media mañana.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Snack Times
    |--------------------------------------------------------------------------
    */
    'snack_times' => [
        'morning' => 'media mañana',
        'afternoon' => 'media tarde',
    ],

    /*
    |--------------------------------------------------------------------------
    | General Recommendations
    |--------------------------------------------------------------------------
    */
    'recommendations' => [
        'hydration' => 'Hidratación: consume al menos 2 litros de agua al día',
        'main_meals' => 'Las 3 comidas principales son obligatorias: Desayuno, Almuerzo y Cena',
        'snack_time' => 'Tu snack es para :time',
        'schedule' => 'Respeta los horarios para optimizar tu metabolismo',
        'vegetables' => 'Los vegetales son libres en todas las comidas principales',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meal Structure
    |--------------------------------------------------------------------------
    */
    'meal_structure' => '3 comidas principales + 1 snack (:snack)',

    /*
    |--------------------------------------------------------------------------
    | Tips
    |--------------------------------------------------------------------------
    */
    'tips' => [
        'snack_am' => 'Snack de media mañana para mantener energía',
        'snack_pm' => 'Snack de media tarde para evitar llegar con mucha hambre a la cena',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vegetables Section
    |--------------------------------------------------------------------------
    */
    'vegetables' => [
        'recommendation' => 'Consumo mínimo de 100 kcal en vegetales por comida principal',

        'regular' => [
            'mixed_salad' => [
                'name' => 'Ensalada completa mixta',
                'portion' => '350g (2.5 tazas)',
                'examples' => '2 tazas lechuga mixta + 1 tomate mediano + 1/2 taza zanahoria rallada + 1/4 taza cebolla',
            ],
            'steamed_bowl' => [
                'name' => 'Bowl de vegetales al vapor',
                'portion' => '300g (2 tazas)',
                'examples' => '1 taza brócoli + 1/2 taza zanahoria + 1/2 taza ejotes + 1/2 taza calabaza',
            ],
            'mediterranean_salad' => [
                'name' => 'Ensalada mediterránea',
                'portion' => '320g (2 tazas)',
                'examples' => '1.5 tazas lechuga + 1 tomate + 1/2 pepino + 1/4 taza pimiento + cebolla morada',
            ],
            'sauteed' => [
                'name' => 'Vegetales salteados',
                'portion' => '280g (2 tazas)',
                'examples' => '1 taza brócoli + 1/2 taza pimiento + 1/2 taza cebolla + 1/2 taza calabacín',
            ],
        ],

        'keto' => [
            'mixed_green_salad' => [
                'name' => 'Ensalada verde mixta grande',
                'portion' => '400g (2 tazas grandes)',
                'examples' => '2 tazas de lechuga + 1 taza de espinaca + 1/2 taza pepino + 1/4 taza pimiento',
            ],
            'cruciferous_salad' => [
                'name' => 'Ensalada de vegetales crucíferos',
                'portion' => '350g (2 tazas)',
                'examples' => '1 taza brócoli + 1 taza coliflor + 1/2 taza col morada',
            ],
            'low_carb_mix' => [
                'name' => 'Mix de vegetales bajos en carbos',
                'portion' => '380g',
                'examples' => '1.5 tazas espinaca + 1/2 taza champiñones + 1/2 taza calabacín + tomates cherry',
            ],
        ],
    ],
];