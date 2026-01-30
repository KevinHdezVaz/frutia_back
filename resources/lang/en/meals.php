<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meal Names
    |--------------------------------------------------------------------------
    */
    'meal_names' => [
        'desayuno' => 'Breakfast',
        'almuerzo' => 'Lunch',
        'cena' => 'Dinner',
        'snack_am' => 'Morning Snack',
        'snack_pm' => 'Afternoon Snack',
    ],

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */
    'categories' => [
        'proteínas' => 'Proteins',
        'carbohidratos' => 'Carbohydrates',
        'grasas' => 'Fats',
        'vegetales' => 'Vegetables',
    ],

    /*
    |--------------------------------------------------------------------------
    | Personalized Messages
    |--------------------------------------------------------------------------
    */
    'personalized_message' => [
        'snack_am' => 'Hi :name, your plan includes 3 main meals (Breakfast, Lunch, Dinner) and a mid-morning snack, as you prefer.',
        'snack_pm' => 'Hi :name, your plan includes 3 main meals (Breakfast, Lunch, Dinner) and a mid-afternoon snack, as you prefer.',
        'default' => 'Hi :name, your plan includes 3 main meals (Breakfast, Lunch, Dinner) and a mid-morning snack.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Snack Times
    |--------------------------------------------------------------------------
    */
    'snack_times' => [
        'morning' => 'mid-morning',
        'afternoon' => 'mid-afternoon',
    ],

    /*
    |--------------------------------------------------------------------------
    | General Recommendations
    |--------------------------------------------------------------------------
    */
    'recommendations' => [
        'hydration' => 'Hydration: drink at least 2 liters of water per day',
        'main_meals' => 'The 3 main meals are mandatory: Breakfast, Lunch, and Dinner',
        'snack_time' => 'Your snack is for :time',
        'schedule' => 'Follow the schedule to optimize your metabolism',
        'vegetables' => 'Vegetables are unlimited in all main meals',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meal Structure
    |--------------------------------------------------------------------------
    */
    'meal_structure' => '3 main meals + 1 snack (:snack)',

    /*
    |--------------------------------------------------------------------------
    | Tips
    |--------------------------------------------------------------------------
    */
    'tips' => [
        'snack_am' => 'Mid-morning snack to maintain energy',
        'snack_pm' => 'Mid-afternoon snack to avoid arriving too hungry at dinner',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vegetables Section
    |--------------------------------------------------------------------------
    */
    'vegetables' => [
        'recommendation' => 'Minimum consumption of 100 kcal in vegetables per main meal',

        'regular' => [
            'mixed_salad' => [
                'name' => 'Complete mixed salad',
                'portion' => '350g (2.5 cups)',
                'examples' => '2 cups mixed lettuce + 1 medium tomato + 1/2 cup shredded carrot + 1/4 cup onion',
            ],
            'steamed_bowl' => [
                'name' => 'Steamed vegetable bowl',
                'portion' => '300g (2 cups)',
                'examples' => '1 cup broccoli + 1/2 cup carrot + 1/2 cup green beans + 1/2 cup squash',
            ],
            'mediterranean_salad' => [
                'name' => 'Mediterranean salad',
                'portion' => '320g (2 cups)',
                'examples' => '1.5 cups lettuce + 1 tomato + 1/2 cucumber + 1/4 cup bell pepper + red onion',
            ],
            'sauteed' => [
                'name' => 'Sautéed vegetables',
                'portion' => '280g (2 cups)',
                'examples' => '1 cup broccoli + 1/2 cup bell pepper + 1/2 cup onion + 1/2 cup zucchini',
            ],
        ],

        'keto' => [
            'mixed_green_salad' => [
                'name' => 'Large mixed green salad',
                'portion' => '400g (2 large cups)',
                'examples' => '2 cups lettuce + 1 cup spinach + 1/2 cup cucumber + 1/4 cup bell pepper',
            ],
            'cruciferous_salad' => [
                'name' => 'Cruciferous vegetable salad',
                'portion' => '350g (2 cups)',
                'examples' => '1 cup broccoli + 1 cup cauliflower + 1/2 cup red cabbage',
            ],
            'low_carb_mix' => [
                'name' => 'Low-carb vegetable mix',
                'portion' => '380g',
                'examples' => '1.5 cups spinach + 1/2 cup mushrooms + 1/2 cup zucchini + cherry tomatoes',
            ],
        ],
    ],
];