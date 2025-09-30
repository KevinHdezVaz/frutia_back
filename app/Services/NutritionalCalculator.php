    <?php

    namespace App\Services;

    class NutritionalCalculator 
    {
        /**
         * Calcula porciones ajustadas considerando macros cruzados
         */
        public function calculateAdjustedPortions($targetMacros, $mealPercentage) 
        {
            $mealProtein = $targetMacros['protein'] * $mealPercentage;
            $mealCarbs = $targetMacros['carbohydrates'] * $mealPercentage;
            $mealFats = $targetMacros['fats'] * $mealPercentage;
            
            // Aplicar factor de reducción para compensar macros secundarios
            return [
                'protein' => [
                    'primary' => $mealProtein * 0.85,  // 85% del objetivo
                    'from_carbs' => $mealProtein * 0.10, // 10% vendrá de carbos
                    'from_fats' => $mealProtein * 0.05   // 5% vendrá de grasas
                ],
                'carbohydrates' => [
                    'primary' => $mealCarbs * 0.90,      // 90% del objetivo
                    'buffer' => $mealCarbs * 0.10        // 10% de margen
                ],
                'fats' => [
                    'primary' => $mealFats * 0.80,       // 80% del objetivo
                    'buffer' => $mealFats * 0.20         // 20% de margen
                ]
            ];
        }
    }