<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class RecipeGenerationService
{
    public function generatePersonalizedRecipes(array $planData, $profile, $nutritionalData): array
    {
        $allMeals = array_keys($planData['nutritionPlan']['meals'] ?? []);
        $mealsToSearch = array_filter($allMeals, function ($mealName) {
            return !str_contains(strtolower($mealName), __('fruit_snack')) &&
                   !str_contains(strtolower($mealName), __('fruit'));
        });

        if (empty($mealsToSearch)) {
            return $planData;
        }

        $profileData = [
            'name' => $nutritionalData['basic_data']['preferences']['preferred_name'] ?? $nutritionalData['basic_data']['preferences']['name'] ?? __('user'),
            'goal' => $nutritionalData['basic_data']['goal'] ?? '',
            'weight' => $nutritionalData['basic_data']['weight'] ?? 0,
            'height' => $nutritionalData['basic_data']['height'] ?? 0,
            'age' => $nutritionalData['basic_data']['age'] ?? 0,
            'sex' => $nutritionalData['basic_data']['sex'] ?? '',
            'dietary_style' => $nutritionalData['basic_data']['preferences']['dietary_style'] ?? __('omnivorous'),
            'budget' => $nutritionalData['basic_data']['preferences']['budget'] ?? '',
            'disliked_foods' => $nutritionalData['basic_data']['preferences']['disliked_foods'] ?? '',
            'allergies' => $nutritionalData['basic_data']['health_status']['allergies'] ?? '',
            'has_allergies' => $nutritionalData['basic_data']['health_status']['has_allergies'] ?? false,
            'medical_condition' => $nutritionalData['basic_data']['health_status']['medical_condition'] ?? '',
            'has_medical_condition' => $nutritionalData['basic_data']['health_status']['has_medical_condition'] ?? false,
            'country' => $nutritionalData['basic_data']['country'] ?? __('mexico'),
            'sports' => $nutritionalData['basic_data']['sports_data']['sports'] ?? [],
            'training_frequency' => $nutritionalData['basic_data']['sports_data']['training_frequency'] ?? '',
            'weekly_activity' => $nutritionalData['basic_data']['activity_level'] ?? '',
            'communication_style' => $nutritionalData['basic_data']['preferences']['communication_style'] ?? '',
            'eats_out' => $nutritionalData['basic_data']['preferences']['eats_out'] ?? '',
            'meal_count' => $nutritionalData['basic_data']['preferences']['meal_count'] ?? '',
            'diet_difficulties' => $nutritionalData['basic_data']['emotional_profile']['diet_difficulties'] ?? [],
            'diet_motivations' => $nutritionalData['basic_data']['emotional_profile']['diet_motivations'] ?? [],
            'meal_times' => $nutritionalData['basic_data']['meal_times'] ?? [
                'breakfast_time' => '07:00',
                'lunch_time' => '13:00',
                'dinner_time' => '20:00'
            ],
            'bmi' => $nutritionalData['basic_data']['anthropometric_data']['bmi'] ?? 0,
            'weight_status' => $nutritionalData['basic_data']['anthropometric_data']['weight_status'] ?? '',
            'target_calories' => $nutritionalData['target_calories'] ?? 0,
            'target_protein' => $nutritionalData['macros']['protein']['grams'] ?? 0,
            'target_carbs' => $nutritionalData['macros']['carbohydrates']['grams'] ?? 0,
            'target_fats' => $nutritionalData['macros']['fats']['grams'] ?? 0
        ];

        foreach ($mealsToSearch as $mealName) {
            if (isset($planData['nutritionPlan']['meals'][$mealName])) {
                $mealComponents = $planData['nutritionPlan']['meals'][$mealName];

                $mealPercentages = [
                    'breakfast' => 0.30,
                    'lunch' => 0.40,
                    'dinner' => 0.30
                ];

                $mealPercentage = $mealPercentages[$mealName] ?? 0.33;

                $profileData['meal_target_protein'] = round($profileData['target_protein'] * $mealPercentage);
                $profileData['meal_target_carbs'] = round($profileData['target_carbs'] * $mealPercentage);
                $profileData['meal_target_fats'] = round($profileData['target_fats'] * $mealPercentage);
                $profileData['meal_target_calories'] = round($profileData['target_calories'] * $mealPercentage);

                $recipes = $this->generateUltraPersonalizedRecipesForMeal(
                    $mealComponents,
                    $profileData,
                    $nutritionalData,
                    $mealName
                );

                if (!empty($recipes)) {
                    $validRecipes = [];

                    foreach ($recipes as $recipe) {
                        if ($this->validateRecipeIngredients($recipe, $profileData)) {
                            $validRecipes[] = $recipe;
                        } else {
                            Log::warning("Recipe rejected for containing prohibited ingredients", [
                                'recipe' => $recipe['name'] ?? 'No name',
                                'meal' => $mealName
                            ]);
                        }
                    }

                    if (!empty($validRecipes)) {
                        $planData['nutritionPlan']['meals'][$mealName]['suggested_recipes'] = $validRecipes;
                        $planData['nutritionPlan']['meals'][$mealName]['meal_timing'] = $this->getMealTiming($mealName, $profileData['meal_times']);
                        $planData['nutritionPlan']['meals'][$mealName]['personalized_tips'] = $this->getMealSpecificTips($mealName, $profileData);
                        Log::info(count($validRecipes) . " ultra-personalized validated recipes for {$mealName}");
                    }
                }
            }
        }

        return $planData;
    }

    public function addTrialMessage(array $planData, string $userName): array
    {
        if (isset($planData['nutritionPlan']['meals'])) {
            foreach ($planData['nutritionPlan']['meals'] as $mealName => &$mealData) {
                $mealData['trial_message'] = [
                    'title' => __('personalized_recipes'),
                    'message' => __("hello :name personalized_recipes_available_with_full_subscription", ['name' => $userName]),
                    'upgrade_hint' => __('activate_subscription_to_access_step_by_step_recipes')
                ];
            }
        }

        return $planData;
    }

    private function generateUltraPersonalizedRecipesForMeal(array $mealComponents, array $profileData, $nutritionalData, $mealName): ?array
    {
        $proteinOptions = [];
        $carbOptions = [];
        $fatOptions = [];

        if (isset($mealComponents['Proteins']['options'])) {
            $proteinOptions = array_map(fn($opt) => $opt['name'] . ' (' . $opt['portion'] . ')', $mealComponents['Proteins']['options']);
        }

        if (isset($mealComponents['Carbs']['options'])) {
            $carbOptions = array_map(fn($opt) => $opt['name'] . ' (' . $opt['portion'] . ')', $mealComponents['Carbs']['options']);
        }

        if (isset($mealComponents['Fats']['options'])) {
            $fatOptions = array_map(fn($opt) => $opt['name'] . ' (' . $opt['portion'] . ')', $mealComponents['Fats']['options']);
        }

        if (empty($proteinOptions)) {
            $budget = strtolower($profileData['budget']);
            if (str_contains($budget, 'low') || str_contains($budget, 'bajo')) {
                $proteinOptions = ['Greek yogurt', 'Canned tuna'];
            } else {
                $proteinOptions = ['Protein powder', 'High-protein Greek yogurt', 'Casein'];
            }
        }

        if (empty($carbOptions)) {
            $dietStyle = strtolower($profileData['dietary_style']);
            if (str_contains($dietStyle, 'keto')) {
                $carbOptions = ['Green vegetables', 'Cauliflower', 'Broccoli', 'Spinach'];
            } else {
                $carbOptions = ['Rice', 'Quinoa', 'Potato', 'Oats', 'Whole wheat bread'];
            }
        }

        if (empty($fatOptions)) {
            $budget = strtolower($profileData['budget']);
            if (str_contains($budget, 'low') || str_contains($budget, 'bajo')) {
                $fatOptions = ['Vegetable oil', 'Peanuts', 'Small avocado'];
            } else {
                $fatOptions = ['Extra virgin olive oil', 'Almonds', 'Hass avocado', 'Walnuts'];
            }
        }

        $proteinString = implode(', ', array_unique($proteinOptions));
        $carbString = implode(', ', array_unique($carbOptions));
        $fatString = implode(', ', array_unique($fatOptions));

        $dislikedFoodsList = !empty($profileData['disliked_foods'])
            ? array_map('trim', explode(',', $profileData['disliked_foods']))
            : [];

        $allergiesList = !empty($profileData['allergies'])
            ? array_map('trim', explode(',', $profileData['allergies']))
            : [];

        $isSnack = str_contains(strtolower($mealName), 'snack');

        $needsPortable = str_contains(strtolower($profileData['eats_out']), __('almost_all')) ||
                         str_contains(strtolower($profileData['eats_out']), __('times'));

        $needsQuick = in_array(__('prepare_food'), $profileData['diet_difficulties']) ||
                      in_array(__('no_time_to_cook'), $profileData['diet_difficulties']);

        $needsAlternatives = in_array(__('know_what_to_eat_when_not_having_plan_items'), $profileData['diet_difficulties']);

        $communicationTone = '';
        if (str_contains(strtolower($profileData['communication_style']), __('motivational'))) {
            $communicationTone = __("use_motivational_energetic_tone", ['name' => $profileData['name']]);
        } elseif (str_contains(strtolower($profileData['communication_style']), __('direct'))) {
            $communicationTone = __("use_direct_clear_tone");
        } elseif (str_contains(strtolower($profileData['communication_style']), __('close'))) {
            $communicationTone = __("use_close_friendly_tone");
        }

        $snackRules = '';
        if ($isSnack) {
            $snackRules = "
    🍎 **" . __('critical_rules_for_snacks_mandatory') . "**
    ⚠️ " . __('this_is_a_snack_not_full_meal') . "
    **" . __('prohibited_ingredients_in_snacks') . "**
    - ❌ " . __('never_use_meats') . "
    - ❌ " . __('never_use_complex_cooking') . "
    - ❌ " . __('never_use_more_than_5_ingredients') . "
    **" . __('allowed_ingredients_in_snacks') . "**
    - ✅ " . __('greek_yogurt_protein_powder_casein') . "
    - ✅ " . __('fresh_fruits') . "
    - ✅ " . __('cereals_oats_granola_rice_crackers') . "
    - ✅ " . __('nuts_almonds_walnuts_peanuts') . "
    - ✅ " . __('peanut_butter_honey_dark_chocolate') . "
    **" . __('mandatory_characteristics') . "**
    - " . __('preparation_max_10_minutes') . "
    - " . __('ingredients_max_5') . "
    - " . __('must_be_100_portable') . "
    - " . __('no_cooking_or_minimal') . "
    - " . __('calories_exactly :calories (max 220)', ['calories' => $profileData['meal_target_calories']]) . "
    **" . __('correct_snack_examples') . "**
    ✅ " . __('greek_yogurt_granola_strawberries_honey') . "
    ✅ " . __('protein_shake_banana_peanut_butter') . "
    ✅ " . __('oats_milk_blueberries_almonds') . "
    ✅ " . __('rice_crackers_cottage_cheese_fruits') . "
    **" . __('prohibited_snack_examples') . "**
    ❌ " . __('chicken_tacos_full_meal') . "
    ❌ " . __('salmon_salad_full_meal') . "
    ❌ " . __('meat_bowl_full_meal') . "
    ";
        }

        $prompt = "
    " . __('you_are_personal_chef_nutritionist', ['name' => $profileData['name']]) . "
    🔴 **" . __('absolute_restrictions_never_violate') . "**
    " . (!empty($dislikedFoodsList) ?
"- " . __('forbidden_use_disliked_foods', ['foods' => implode(', ', $dislikedFoodsList)]) :
"- " . __('no_disliked_foods')) . "
    " . (!empty($allergiesList) ?
"- " . __('deadly_allergies_never_include', ['allergies' => implode(', ', $allergiesList)]) :
"- " . __('no_reported_allergies')) . "
    " . (!empty($profileData['medical_condition']) ?
"- " . __('medical_condition_to_consider', ['condition' => $profileData['medical_condition']]) :
"- " . __('no_special_medical_conditions')) . "
    📊 **" . __('complete_profile_of :name', ['name' => $profileData['name']]) . "**
    - " . __('age') . ": {$profileData['age']} " . __('years') . ", " . __('sex') . ": {$profileData['sex']}
    - " . __('weight') . ": {$profileData['weight']}kg, " . __('height') . ": {$profileData['height']}cm, BMI: " . round($profileData['bmi'], 1) . "
    - " . __('physical_status') . ": {$profileData['weight_status']}
    - " . __('country') . ": {$profileData['country']} (" . __('use_local_available_ingredients') . ")
    - " . __('main_goal') . ": {$profileData['goal']}
    - " . __('weekly_activity') . ": {$profileData['weekly_activity']}
    - " . __('sports_practiced') . ": " . (!empty($profileData['sports']) ? implode(', ', $profileData['sports']) : __('none_specific')) . "
    - " . __('dietary_style') . ": {$profileData['dietary_style']}
    - " . __('budget') . ": {$profileData['budget']}
    - " . __('eats_out') . ": {$profileData['eats_out']}
    - " . __('meal_structure') . ": {$profileData['meal_count']}
    - " . __('specific_time_for :meal', ['meal' => $mealName]) . ": " . $this->getMealTiming($mealName, $profileData['meal_times']) . "
    🎯 **" . __('nutritional_targets_for_this :meal', ['meal' => $mealName]) . "**
    - " . __('target_calories') . ": {$profileData['meal_target_calories']} kcal
    - " . __('target_protein') . ": {$profileData['meal_target_protein']}g
    - " . __('target_carbs') . ": {$profileData['meal_target_carbs']}g
    - " . __('target_fats') . ": {$profileData['meal_target_fats']}g
    💪 **" . __('specific_difficulties_to_solve') . "**
    " . (!empty($profileData['diet_difficulties']) ?
implode("\n", array_map(fn($d) => "- {$d} → " . __('propose_specific_solution'), $profileData['diet_difficulties'])) :
"- " . __('no_specific_difficulties_reported')) . "
    🌟 **" . __('motivations_to_reinforce') . "**
    " . (!empty($profileData['diet_motivations']) ?
implode("\n", array_map(fn($m) => "- {$m} → " . __('connect_recipe_with_this_motivation'), $profileData['diet_motivations'])) :
"- " . __('general_health_motivation')) . "
    🛒 **" . __('base_ingredients_available_for :name', ['name' => $profileData['name']]) . "**
    - " . __('proteins') . ": {$proteinString}
    - " . __('carbs') . ": {$carbString}
    - " . __('fats') . ": {$fatString}
    {$snackRules}
    📋 **" . __('special_generation_rules') . "**
    " . ($needsPortable ? "- " . __('include_at_least_1_portable_recipe') : "") . "
    " . ($needsQuick ? "- " . __('recipes_must_be_quick_max_20_minutes') : "") . "
    " . ($needsAlternatives ? "- " . __('give_alternatives_for_each_main_ingredient') : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'keto') ?
"- " . __('strict_keto_max_5g_net_carbs_per_recipe') : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'vegan') ?
"- " . __('vegan_only_plant_based_ingredients') : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'vegetarian') ?
"- " . __('vegetarian_no_meat_no_fish') : "") . "
    {$communicationTone}
    **" . __('mandatory_json_structure') . "**
    " . __('generate_exactly_3_different_creative_recipes', ['name' => $profileData['name']]) . ":
    {
\"recipes\": [
        {
\"name\": \"" . __('creative_name_authentic_from :country', ['country' => $profileData['country']]) . "\",
\"personalizedNote\": \"" . __('personal_note_for :name_explaining_why_perfect', ['name' => $profileData['name'], 'goal' => $profileData['goal']]) . "\",
\"instructions\": \"" . __('step_1_instruction') . "\\n" . __('step_2_next') . "\\n" . __('step_3_completion') . "\\n" . __('personal_tip: tip_for :name', ['name' => $profileData['name']]) . "\",
\"readyInMinutes\": " . ($isSnack ? "10" : "20") . ",
\"servings\": 1,
\"calories\": {$profileData['meal_target_calories']},
\"protein\": {$profileData['meal_target_protein']},
\"carbs\": {$profileData['meal_target_carbs']},
\"fats\": {$profileData['meal_target_fats']},
\"extendedIngredients\": [
            {
\"name\": \"" . __('main_ingredient') . "\",
\"original\": \"" . __('specific_amount_weight_measure') . "\",
\"localName\": \"" . __('local_name_in :country', ['country' => $profileData['country']]) . "\",
\"alternatives\": \"" . __('alternatives_if_not_available') . "\"
            }
          ],
\"cuisineType\": \"{$profileData['country']}\",
\"difficultyLevel\": \"" . __('easy_intermediate_advanced') . "\",
\"goalAlignment\": \"" . __('specific_explanation_how_recipe_helps_with :goal', ['goal' => $profileData['goal']]) . "\",
\"sportsSupport\": \"" . __('how_supports_training_of :sports', ['sports' => implode(', ', $profileData['sports'])]) . "\",
\"portableOption\": " . ($needsPortable || $isSnack ? "true" : "false") . ",
\"quickRecipe\": " . ($needsQuick || $isSnack ? "true" : "false") . ",
\"dietCompliance\": \"" . __('complies_with :style diet', ['style' => $profileData['dietary_style']]) . "\",
\"specialTips\": \"" . __('tips_to_overcome :difficulties', ['difficulties' => implode(', ', array_slice($profileData['diet_difficulties'], 0, 2))]) . "\"
        }
      ]
    }
    " . __('important') . ":
    - " . __('the_3_recipes_must_be_very_different') . "
    - " . __('never_use_prohibited_ingredients') . "
    - " . __('macros_must_be_exact_or_very_close') . "
    - " . __('use_creative_appetizing_recipe_names') . "
    - " . __('instructions_clear_easy_to_follow') . "
    - " . __('mention :name in_personal_notes', ['name' => $profileData['name']]) . "
    " . ($isSnack ? "\n⚠️ " . __('remember_this_is_snack_no_meats') : "") . "
    ";

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(150)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'messages' => [
                        ['role' => 'system', 'content' => __('you_are_expert_chef_nutritionist') . ($isSnack ? ' ' . __('specialize_in_simple_portable_snacks_no_meats') : '')],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.6,
                    'max_tokens' => 4000
                ]);

            if ($response->successful()) {
                $data = json_decode($response->json('choices.0.message.content'), true);

                if (json_last_error() === JSON_ERROR_NONE && isset($data['recipes']) && is_array($data['recipes'])) {
                    $processedRecipes = [];

                    foreach ($data['recipes'] as $recipeData) {
                        if ($isSnack) {
                            $hasProhibitedIngredient = false;
                            $prohibitedInSnacks = ['chicken', 'meat', 'beef', 'pork', 'fish', 'salmon', 'fresh tuna', 'turkey'];
                            foreach ($recipeData['extendedIngredients'] ?? [] as $ingredient) {
                                $ingredientName = strtolower($ingredient['name'] ?? '');
                                foreach ($prohibitedInSnacks as $prohibited) {
                                    if (str_contains($ingredientName, $prohibited)) {
                                        $hasProhibitedIngredient = true;
                                        Log::warning("Snack rejected for containing prohibited ingredient", [
                                            'recipe' => $recipeData['name'] ?? 'No name',
                                            'ingredient' => $ingredient['name'],
                                            'prohibited' => $prohibited
                                        ]);
                                        break 2;
                                    }
                                }
                            }

                            if ($hasProhibitedIngredient) {
                                continue;
                            }
                        }

                        $recipeData['image'] = null;
                        $recipeData['analyzedInstructions'] = $this->parseInstructionsToSteps($recipeData['instructions'] ?? '');
                        $recipeData['personalizedFor'] = $profileData['name'];
                        $recipeData['mealType'] = $mealName;
                        $recipeData['generatedAt'] = now()->toIso8601String();
                        $recipeData['profileGoal'] = $profileData['goal'];
                        $recipeData['budgetLevel'] = $profileData['budget'];

                        if ($this->validateRecipeIngredients($recipeData, $profileData)) {
                            $processedRecipes[] = $recipeData;
                        } else {
                            Log::warning("Recipe generated but rejected for containing prohibited ingredients", [
                                'recipe_name' => $recipeData['name'] ?? 'No name'
                            ]);
                        }
                    }

                    return $processedRecipes;
                }
            }

            Log::error("Error generating personalized recipes", [
                'status' => $response->status(),
                'response' => $response->body(),
                'meal' => $mealName,
                'user' => $profileData['name']
            ]);
        } catch (\Exception $e) {
            Log::error("Exception generating recipes", [
                'error' => $e->getMessage(),
                'meal' => $mealName,
                'user' => $profileData['name']
            ]);
        }

        return null;
    }

    private function validateRecipeIngredients(array $recipe, array $profileData): bool
    {
        $dislikedFoods = !empty($profileData['disliked_foods'])
            ? array_map(fn($f) => trim(strtolower($f)), explode(',', $profileData['disliked_foods']))
            : [];

        $allergies = !empty($profileData['allergies'])
            ? array_map(fn($a) => trim(strtolower($a)), explode(',', $profileData['allergies']))
            : [];

        foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
            $ingredientName = strtolower($ingredient['name'] ?? '');
            $localName = strtolower($ingredient['localName'] ?? '');

            foreach ($dislikedFoods as $disliked) {
                if (!empty($disliked) && (
                    str_contains($ingredientName, $disliked) ||
                    str_contains($localName, $disliked) ||
                    str_contains($disliked, $ingredientName) ||
                    str_contains($disliked, $localName)
                )) {
                    Log::warning("Recipe contains disliked food", [
                        'ingredient' => $ingredient['name'],
                        'disliked_food' => $disliked,
                        'recipe' => $recipe['name'] ?? 'No name',
                        'user' => $profileData['name']
                    ]);
                    return false;
                }
            }

            foreach ($allergies as $allergy) {
                if (!empty($allergy) && (
                    str_contains($ingredientName, $allergy) ||
                    str_contains($localName, $allergy) ||
                    str_contains($allergy, $ingredientName) ||
                    str_contains($allergy, $localName)
                )) {
                    Log::error("CRITICAL ALERT! Recipe contains allergen", [
                        'ingredient' => $ingredient['name'],
                        'allergen' => $allergy,
                        'recipe' => $recipe['name'] ?? 'No name',
                        'user' => $profileData['name']
                    ]);
                    return false;
                }
            }
        }

        $dietaryStyle = strtolower($profileData['dietary_style'] ?? '');

        if (str_contains($dietaryStyle, 'vegan')) {
            $animalProducts = ['egg', 'milk', 'cheese', 'yogurt', 'meat', 'chicken', 'fish', 'seafood', 'honey', 'butter', 'cream', 'ham', 'tuna'];
            foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
                $ingredientName = strtolower($ingredient['name'] ?? '');
                $localName = strtolower($ingredient['localName'] ?? '');
                foreach ($animalProducts as $animal) {
                    if (str_contains($ingredientName, $animal) || str_contains($localName, $animal)) {
                        Log::warning("Recipe is not vegan", [
                            'ingredient' => $ingredient['name'],
                            'recipe' => $recipe['name'] ?? 'No name'
                        ]);
                        return false;
                    }
                }
            }
        }

        if (str_contains($dietaryStyle, 'vegetarian')) {
            $meats = ['meat', 'chicken', 'breast', 'thigh', 'fish', 'seafood', 'tuna', 'salmon', 'ham', 'bacon', 'sausage'];
            foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
                $ingredientName = strtolower($ingredient['name'] ?? '');
                $localName = strtolower($ingredient['localName'] ?? '');
                foreach ($meats as $meat) {
                    if (str_contains($ingredientName, $meat) || str_contains($localName, $meat)) {
                        Log::warning("Recipe is not vegetarian", [
                            'ingredient' => $ingredient['name'],
                            'recipe' => $recipe['name'] ?? 'No name'
                        ]);
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function parseInstructionsToSteps(string $instructions): array
    {
        if (empty($instructions)) {
            return [];
        }

        $steps = [];
        $lines = explode("\n", $instructions);

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (!empty($line) && !str_starts_with(strtolower($line), __('personal_tip'))) {
                $line = preg_replace('/^' . __('step') . ' \d+:\s*/i', '', $line);
                $steps[] = [
                    'number' => $index + 1,
                    'step' => $line
                ];
            }
        }

        return $steps;
    }

    private function getMealTiming($mealName, $mealTimes): string
    {
        switch ($mealName) {
            case 'breakfast':
                return $mealTimes['breakfast_time'] ?? '07:00';
            case 'lunch':
                return $mealTimes['lunch_time'] ?? '13:00';
            case 'dinner':
                return $mealTimes['dinner_time'] ?? '20:00';
            case 'protein_snack':
                return '16:00';
            default:
                return '12:00';
        }
    }

    private function getMealSpecificTips($mealName, array $profileData): array
    {
        $tips = [];
        $mealLower = strtolower($mealName);

        if (str_contains($mealLower, __('breakfast'))) {
            $tips[] = __("breakfast_designed_for_sustained_energy_until_lunch");
            if (!empty($profileData['sports']) && in_array('Gym', $profileData['sports'])) {
                $tips[] = __("perfect_as_pre_workout_if_you_go_to_the_gym_in_the_morning");
            }
            if (str_contains(strtolower($profileData['goal']), __('lose_fat'))) {
                $tips[] = __("high_in_protein_to_activate_your_metabolism_early");
            }
        } elseif (str_contains($mealLower, __('lunch'))) {
            $tips[] = __("your_main_meal_of_the_day_with_40%_of_your_nutrients");
            if (str_contains($profileData['weekly_activity'], __('active_work'))) {
                $tips[] = __("energy_to_maintain_your_performance_in_your_active_work");
            }
        } elseif (str_contains($mealLower, __('dinner'))) {
            $tips[] = __("balanced_dinner_for_optimal_night_recovery");
            if (str_contains(strtolower($profileData['goal']), __('increase_muscle'))) {
                $tips[] = __("rich_in_slow_absorption_proteins_for_night_muscle_synthesis");
            }
        }

        if (in_array(__('control_cravings'), $profileData['diet_difficulties'])) {
            $tips[] = __("rich_in_fiber_and_protein_to_maintain_satiety_and_avoid_cravings");
        }

        if (in_array(__('prepare_food'), $profileData['diet_difficulties'])) {
            $tips[] = __("you_can_prepare_double_and_save_for_tomorrow");
        }

        return $tips;
    }
}