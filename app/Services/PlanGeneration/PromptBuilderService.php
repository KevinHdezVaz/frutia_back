<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;

class PromptBuilderService
{
    public function buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName, $attemptNumber = 1): string
    {
        $macros = $nutritionalData['macros'];
        $basicData = $nutritionalData['basic_data'];
        $foodPreferences = $nutritionalData['food_preferences'] ?? [
            'proteins' => [],
            'carbs' => [],
            'fats' => [],
            'fruits' => []
        ];

        $favoritesSection = $this->buildFavoritesPromptSection($foodPreferences, $userName);

        $preferredName = $userName;
        $communicationStyle = $basicData['preferences']['communication_style'];
        $sports = !empty($basicData['sports_data']['sports']) ? implode(', ', $basicData['sports_data']['sports']) : __('none_specified');
        $mealTimes = $basicData['meal_times'];

        $difficulties = !empty($basicData['emotional_profile']['diet_difficulties']) ? implode(', ', $basicData['emotional_profile']['diet_difficulties']) : __('none_specified');
        $motivations = !empty($basicData['emotional_profile']['diet_motivations']) ? implode(', ', $basicData['emotional_profile']['diet_motivations']) : __('none_specified');

        $dislikedFoodsPrompt = '';
        if (!empty($basicData['preferences']['disliked_foods'])) {
            $dislikedList = $basicData['preferences']['disliked_foods'];
            $dislikedFoodsPrompt = "
🔴 **" . __('foods_that_:name_does_not_want_to_eat', ['name' => $userName]) . "**
{$dislikedList}
⚠️ " . __('absolute_prohibition_never_violate') . "
- " . __('never_use_these_foods_in_any_recipe') . "
- " . __('if_prohibited_food_key_use_alternatives') . "
  * " . __('no_chicken_use_canned_tuna_whole_egg_ground_beef_white_fish') . "
  * " . __('no_rice_use_potato_sweet_potato_noodles_quinoa') . "
  * " . __('no_avocado_use_peanuts_vegetable_oil_almonds') . "
  * " . __('no_egg_use_canned_tuna_chicken_breast_greek_yogurt') . "
  * " . __('no_dairy_use_plant_milks_tofu_legumes') . "
- " . __('each_recipe_must_respect_restrictions') . "
- " . __('if_not_enough_alternatives_inform_user') . "
";
        }

        $allergiesPrompt = '';
        if (!empty($basicData['health_status']['allergies'])) {
            $allergiesList = $basicData['health_status']['allergies'];
            $allergiesPrompt = "
🚨 **" . __('critical_food_allergies_death_danger') . "**
{$allergiesList}
⚠️⚠️⚠️ " . __('maximum_warning') . ":
- " . __('these_foods_can_kill_:name', ['name' => $userName]) . "
- " . __('never_include_even_traces') . "
- " . __('check_hidden_ingredients') . "
- " . __('in_case_of_doubt_do_not_include') . "
";
        }

        $budget = $basicData['preferences']['budget'];
        $budgetType = str_contains(strtolower($budget), 'low') || str_contains(strtolower($budget), 'bajo') ? 'LOW' : 'HIGH';

        $dietaryInstructions = $this->getDetailedDietaryInstructions($basicData['preferences']['dietary_style']);
        $budgetInstructions = $this->getDetailedBudgetInstructions($budget, $basicData['country']);
        $communicationInstructions = $this->getCommunicationStyleInstructions($communicationStyle, $preferredName);
        $countrySpecificFoods = $this->getCountrySpecificFoods($basicData['country'], $budget);

        $attemptEmphasis = $attemptNumber > 1 ? "
    ⚠️ " . __('attention_attempt_:number_previous_failed', ['number' => $attemptNumber]) . "
    " . __('critical_follow_all_instructions') . "
    " : "";

        $deficitInfo = '';
        if (str_contains(strtolower($basicData['goal']), 'lose fat') || str_contains(strtolower($basicData['goal']), 'bajar grasa')) {
            $sex = strtolower($basicData['sex']);
            $deficitPercentage = ($sex === 'femenino' || $sex === 'female') ? '25%' : '35%';
            $deficitInfo = "
    📊 **" . __('caloric_deficit_applied') . "**
    - " . __('sex') . ": {$basicData['sex']}
    - " . __('deficit') . ": {$deficitPercentage} (GET: {$nutritionalData['get']} kcal → " . __('target') . ": {$nutritionalData['target_calories']} kcal)
    " . (($sex === 'femenino' || $sex === 'female') ?
"- " . __('moderate_deficit_women_avoid_low_calories') :
"- " . __('aggressive_deficit_men'));
        }

        return "
    " . __('you_are_expert_nutritionist_ultra_personalized') . "
    " . __('your_client_named_:name', ['name' => $preferredName]) . "
    {$attemptEmphasis}
    {$deficitInfo}
    {$favoritesSection}
    🔴 " . __('critical_rules_budget_:type', ['type' => $budgetType]) . " 🔴
    **" . __('rule_1_foods_by_budget_:type', ['type' => $budgetType]) . "**
    **" . __('rule_1_5_special_food_restrictions') . "**
- ❌ " . __('quinoa_forbidden_breakfast_only_lunch_dinner') . "
- ⚠️ " . __('sweet_potato_peanuts_last_resort') . "
    " . ($budgetType === 'HIGH' ? "
    ✅ " . __('mandatory_use_premium_foods') . ":
    " . __('breakfast_proteins_egg_whites_yogurt_whey') . "
    " . __('lunch_dinner_proteins_chicken_salmon_tuna_beef') . "
    " . __('carbs_quinoa_oats_bread_sweet_potato_rice') . "
    " . __('fats_olive_oil_almonds_walnuts_avocado') . "
    ❌ " . __('forbidden_high_budget_whole_egg_thigh_tuna_oil_peanuts_rice_bread') . "
    " : "
    ✅ " . __('mandatory_use_economical_foods') . ":
    " . __('proteins_whole_egg_thigh_tuna_beef') . "
    " . __('carbs_rice_potato_oats_tortilla_noodles_beans') . "
    " . __('fats_vegetable_oil_peanuts_small_avocado') . "
    ❌ " . __('forbidden_low_budget_salmon_chicken_quinoa_almonds_olive_oil_powder') . "
    ") . "
    **" . __('rule_2_mandatory_variety') . "**
    - " . __('eggs_max_once_per_day') . "
    - " . __('no_repeat_protein_more_than_twice') . "
    - " . __('different_options_per_meal') . "
    **" . __('rule_3_exact_macros_must_match') . "**
    " . __('total_daily_sum_must_be') . ":
    - " . __('proteins_:grams_g_tolerance_5g', ['grams' => $macros['protein']['grams']]) . "
    - " . __('carbs_:grams_g_tolerance_10g', ['grams' => $macros['carbohydrates']['grams']]) . "
    - " . __('fats_:grams_g_tolerance_5g', ['grams' => $macros['fats']['grams']]) . "
    - " . __('total_calories_:calories_kcal', ['calories' => $macros['calories']]) . "
    **" . __('rule_4_account_everything_mandatory') . "**
    - ✅ " . __('mandatory_minimum_vegetables_main_meals') . "
    - " . __('vegetables_not_free_minimum_required') . "
    - " . __('include_sauces_dressings_calories') . ":
      * " . __('olive_oil_salad_10ml_90kcal') . "
      * " . __('tomato_sauce_50ml_25kcal') . "
      * " . __('lemon_negligible') . "
      * " . __('balsamic_15ml_15kcal') . "
      * " . __('light_mayo_15g_30kcal') . "
    - " . __('vegetable_range_100_150kcal_main_meal') . "
    - " . __('vegetables_contribute_total_macros') . "
    **" . __('vegetable_portions_100kcal') . "**
    - " . __('2.5_cups_mixed_salad_tomato_350g') . "
    - " . __('2_cups_steamed_vegetables_300g') . "
    - " . __('400g_green_salad') . "
    - " . __('2_cups_sauteed_vegetables_280g') . "
    **" . __('important_vegetables_add_meal_macros') . "**
    **" . __('rule_5_mandatory_micronutrients') . "**
    - " . __('fiber_min_10g_main_meal_daily_30_40g') . "
    - " . __('vitamins_sources_c_d_iron') . "
    - " . __('minerals_calcium_magnesium_potassium') . "
    - " . __('meal_color_variety_phytonutrients') . "
    - " . __('vegetables_100kcal_6_9g_fiber') . "
    ⚠️⚠️⚠️ " . __('common_error_avoid') . ":
    " . __('previous_plans_failed_high_fats_low_carbs') . "
    ✅ " . __('correct_40_40_20_formula') . ":
    - " . __('proteins_formula', ['calories' => $macros['calories'], 'grams' => $macros['protein']['grams']]) . "
    - " . __('carbs_formula', ['calories' => $macros['calories'], 'grams' => $macros['carbohydrates']['grams']]) . "
    - " . __('fats_formula', ['calories' => $macros['calories'], 'grams' => $macros['fats']['grams']]) . "
    " . __('if_calculations_differ_check_math') . "
    **" . __('meal_distribution') . "**
    - " . __('breakfast_30_percent') . "
    - " . __('lunch_40_percent') . "
    - " . __('dinner_30_percent') . "
    **" . __('calculated_nutritional_info') . "**
    - TMB: {$nutritionalData['tmb']} kcal
    - GET: {$nutritionalData['get']} kcal
    - " . __('target_calories') . ": {$nutritionalData['target_calories']} kcal
    - " . __('activity_factor') . ": {$nutritionalData['activity_factor']}
    **{$preferredName} " . __('profile') . "**
    - " . __('age_years_sex') . ": {$basicData['age']} " . __('years') . ", {$basicData['sex']}
    - " . __('weight_height_bmi_status') . ": {$basicData['weight']} kg, {$basicData['height']} cm, BMI: {$basicData['anthropometric_data']['bmi']} ({$basicData['anthropometric_data']['weight_status']})
    - " . __('country') . ": {$basicData['country']}
    - " . __('goal') . ": {$basicData['goal']}
    - " . __('sports') . ": {$sports}
    - " . __('dietary_style') . ": {$basicData['preferences']['dietary_style']}
    - " . __('disliked_foods') . ": {$basicData['preferences']['disliked_foods']}
    - " . __('allergies') . ": {$basicData['health_status']['allergies']}
    - " . __('eats_out') . ": {$basicData['preferences']['eats_out']}
    - " . __('difficulties') . ": {$difficulties}
    - " . __('motivations') . ": {$motivations}
    {$dislikedFoodsPrompt}
    {$allergiesPrompt}
    {$budgetInstructions}
    {$dietaryInstructions}
    {$communicationInstructions}
    **" . __('specific_ingredients_country_:country', ['country' => strtoupper($basicData['country'])]) . "**
    {$countrySpecificFoods}
    **" . __('mandatory_verification_before_responding') . "**
    🔴🔴🔴 " . __('step_by_step_math_calculation') . " 🔴🔴🔴
    **" . __('step_1_macros_per_meal_calculated') . "**
    " . __('breakfast_30') . ":
    - " . __('proteins') . ": " . round($macros['protein']['grams'] * 0.30) . "g
    - " . __('carbs') . ": " . round($macros['carbohydrates']['grams'] * 0.30) . "g
    - " . __('fats') . ": " . round($macros['fats']['grams'] * 0.30) . "g
    - " . __('calories') . ": ~" . round($macros['calories'] * 0.30) . " kcal
    " . __('lunch_40') . ":
    - " . __('proteins') . ": " . round($macros['protein']['grams'] * 0.40) . "g
    - " . __('carbs') . ": " . round($macros['carbohydrates']['grams'] * 0.40) . "g
    - " . __('fats') . ": " . round($macros['fats']['grams'] * 0.40) . "g
    - " . __('calories') . ": ~" . round($macros['calories'] * 0.40) . " kcal
    " . __('dinner_30') . ":
    - " . __('proteins') . ": " . round($macros['protein']['grams'] * 0.30) . "g
    - " . __('carbs') . ": " . round($macros['carbohydrates']['grams'] * 0.30) . "g
    - " . __('fats') . ": " . round($macros['fats']['grams'] * 0.30) . "g
    - " . __('calories') . ": ~" . round($macros['calories'] * 0.30) . " kcal
    **" . __('step_2_portion_formula') . "**
    " . __('for_each_food_use_mandatory_formula') . ":
    " . __('portion_grams = (meal_target ÷ per_100g) × 100') . "
    📝 " . __('real_examples_breakfast_proteins_need_:grams', ['grams' => round($macros['protein']['grams'] * 0.30)]) . ":
    • " . __('if_egg_whites_11g_protein_100g') . "
      → " . __('portion = (:grams ÷ 11) × 100 = :result g', ['grams' => round($macros['protein']['grams'] * 0.30), 'result' => round(($macros['protein']['grams'] * 0.30 / 11) * 100)]) . "
    • " . __('if_high_protein_yogurt_20g_protein_100g') . "
      → " . __('portion = (:grams ÷ 20) × 100 = :result g', ['grams' => round($macros['protein']['grams'] * 0.30), 'result' => round(($macros['protein']['grams'] * 0.30 / 20) * 100)]) . "
    " . __('breakfast_carbs_need_:grams', ['grams' => round($macros['carbohydrates']['grams'] * 0.30)]) . ":
    • " . __('if_organic_oats_67g_carbs_100g') . "
      → " . __('portion = (:grams ÷ 67) × 100 = :result g', ['grams' => round($macros['carbohydrates']['grams'] * 0.30), 'result' => round(($macros['carbohydrates']['grams'] * 0.30 / 67) * 100)]) . "
    **" . __('step_3_verify_total_sum_critical') . "**
    " . __('after_all_portions_sum_primary_options') . ":
    ✓ " . __('total_proteins_:grams_g_tolerance_5g', ['grams' => $macros['protein']['grams']]) . "
    ✓ " . __('total_carbs_:grams_g_tolerance_10g', ['grams' => $macros['carbohydrates']['grams']]) . "
    ✓ " . __('total_fats_:grams_g_tolerance_5g', ['grams' => $macros['fats']['grams']]) . "
    ⚠️⚠️⚠️ " . __('if_not_match_adjust_portions') . " ⚠️⚠️⚠️
    **" . __('step_4_final_checklist') . "**
    " . __('before_json_verify') . ":
    1. ✓ " . __('all_foods_budget_:type?', ['type' => $budgetType]) . "
    2. ✓ " . __('eggs_max_once_day?') . "
    3. ✓ " . __('variety_meals?') . "
    4. ✓ " . __('quinoa_not_breakfast?') . "
    5. ✓ " . __('weights_correct_cooked_raw?') . "
    6. ✓ " . __('sum_proteins_:grams_g_5g?', ['grams' => $macros['protein']['grams']]) . "
    7. ✓ " . __('sum_carbs_:grams_g_10g?', ['grams' => $macros['carbohydrates']['grams']]) . "
    8. ✓ " . __('sum_fats_:grams_g_5g?', ['grams' => $macros['fats']['grams']]) . "
    🔴 " . __('absolute_restrictions_never_violate') . ":
    " . ($allergiesPrompt ? "- " . __('deadly_allergies_above') : "- " . __('no_allergies')) . "
    " . ($dislikedFoodsPrompt ? "- " . __('unwanted_foods_above') : "- " . __('no_avoid_foods')) . "
    **" . __('mandatory_json_structure') . "**
    {
\"nutritionPlan\": {
        \"personalizedMessage\": \"Mensaje personal para {$preferredName}...\",
        \"anthropometricSummary\": {
          \"clientName\": \"{$preferredName}\",
          \"age\": {$basicData['age']},
          \"sex\": \"{$basicData['sex']}\",
          \"weight\": {$basicData['weight']},
          \"height\": {$basicData['height']},
          \"bmi\": {$basicData['anthropometric_data']['bmi']},
          \"weightStatus\": \"{$basicData['anthropometric_data']['weight_status']}\",
          \"idealWeightRange\": {
            \"min\": {$basicData['anthropometric_data']['ideal_weight_range']['min']},
            \"max\": {$basicData['anthropometric_data']['ideal_weight_range']['max']}
          }
        },
        \"nutritionalSummary\": {
          \"tmb\": {$nutritionalData['tmb']},
          \"get\": {$nutritionalData['get']},
          \"targetCalories\": {$nutritionalData['target_calories']},
          \"goal\": \"{$basicData['goal']}\",
          \"monthlyProgression\": \"Mes 1 de 3 - Ajustes automáticos según progreso\",
          \"activityFactor\": \"{$nutritionalData['activity_factor']} ({$basicData['activity_level']})\",
          \"caloriesPerKg\": " . round($nutritionalData['target_calories'] / $basicData['weight'], 2) . ",
          \"proteinPerKg\":0,
          \"specialConsiderations\": []
        },
        \"targetMacros\": {
          \"calories\": {$macros['calories']},
          \"protein\": {$macros['protein']['grams']},
          \"fats\": {$macros['fats']['grams']},
          \"carbohydrates\": {$macros['carbohydrates']['grams']},
          \"detailedBreakdown\": {
            \"protein\": {
              \"grams\": {$macros['protein']['grams']},
              \"calories\": {$macros['protein']['calories']},
              \"percentage\": {$macros['protein']['percentage']},
              \"perKg\": 0
            },
            \"fats\": {
              \"grams\": {$macros['fats']['grams']},
              \"calories\": {$macros['fats']['calories']},
              \"percentage\": {$macros['fats']['percentage']},
              \"perKg\": 0
            },
            \"carbohydrates\": {
              \"grams\": {$macros['carbohydrates']['grams']},
              \"calories\": {$macros['carbohydrates']['calories']},
              \"percentage\": {$macros['carbohydrates']['percentage']},
              \"perKg\": 0
            }
          }
        },
        \"mealSchedule\": {
          \"breakfast\": \"{$mealTimes['breakfast_time']}\",
          \"lunch\": \"{$mealTimes['lunch_time']}\",
          \"dinner\": \"{$mealTimes['dinner_time']}\"
        },
\"meals\": {
\"breakfast\": {
\"Proteins\": {\"options\": []},
\"Carbs\": {\"options\": []},
\"Fats\": {\"options\": []},
\"Vegetables\": {\"options\": []}
        },
\"lunch\": {},
\"dinner\": {}
      }
    }
    " . __('important') . ":
    - " . __('3_recipes_very_different') . "
    - " . __('never_prohibited_ingredients') . "
    - " . __('macros_exact_close') . "
    - " . __('creative_appetizing_names') . "
    - " . __('clear_easy_instructions') . "
    - " . __('mention_:name_personal_notes', ['name' => $preferredName]) . "
    " . __('generate_complete_plan_:name', ['name' => $preferredName]) . ".
    ";
    }

    private function buildFavoritesPromptSection(array $foodPreferences, string $userName): string
    {
        if (empty($foodPreferences['proteins']) &&
            empty($foodPreferences['carbs']) &&
            empty($foodPreferences['fats']) &&
            empty($foodPreferences['fruits'])) {
            return "";
        }

        $section = "\n\n🌟🌟🌟 **" . __('food_preferences_of_:name', ['name' => $userName]) . "** 🌟🌟🌟\n";
        $section .= __(':name_selected_these_as_favorites_must_prioritize', ['name' => $userName]) . ":\n\n";

        if (!empty($foodPreferences['proteins'])) {
            $section .= "✅ **" . __('favorite_proteins_prioritize_1_2') . ":**\n";
            $section .= " " . implode(', ', $foodPreferences['proteins']) . "\n\n";
        }

        if (!empty($foodPreferences['carbs'])) {
            $section .= "✅ **" . __('favorite_carbs_prioritize_1_2') . ":**\n";
            $section .= " " . implode(', ', $foodPreferences['carbs']) . "\n\n";
        }

        if (!empty($foodPreferences['fats'])) {
            $section .= "✅ **" . __('favorite_fats_prioritize_1_2') . ":**\n";
            $section .= " " . implode(', ', $foodPreferences['fats']) . "\n\n";
        }

        if (!empty($foodPreferences['fruits'])) {
            $section .= "✅ **" . __('favorite_fruits_use_in_snacks') . ":**\n";
            $section .= " " . implode(', ', $foodPreferences['fruits']) . "\n\n";
        }

        $section .= "⚠️ **" . __('critical_prioritization_rule') . ":**\n";
        $section .= "- " . __('favorites_must_appear_as_first_options') . "\n";
        $section .= "- " . __('if_:name_chose_tuna_and_chicken_then', ['name' => $userName]) . ":\n";
        $section .= " ✅ " . __('option_1_canned_tuna_200g') . "\n";
        $section .= " ✅ " . __('option_2_chicken_breast_or_thigh_180g') . "\n";
        $section .= " ✅ " . __('option_3_other_valid_budget_foods') . "\n";
        $section .= "- " . __('non_favorites_can_appear_later') . "\n\n";

        return $section;
    }

    private function getDetailedBudgetInstructions($budget, $country): string
    {
        $budgetLevel = strtolower($budget);

        if (str_contains($budgetLevel, 'low') || str_contains($budgetLevel, 'bajo')) {
            $baseInstructions = "**" . __('low_budget_mandatory_foods') . ":**\n";
            $baseInstructions .= " **" . __('economical_proteins') . ":**\n";
            $baseInstructions .= " - " . __('whole_egg_always_available_economical') . "\n";
            $baseInstructions .= " - " . __('ground_beef_instead_premium_cuts') . "\n";
            $baseInstructions .= " - " . __('chicken_thighs_not_breast') . "\n";
            $baseInstructions .= " - " . __('local_economical_fish_not_salmon') . "\n";
            $baseInstructions .= " - " . __('canned_tuna_practical_option') . "\n";
            $baseInstructions .= " - " . __('legumes_lentils_beans_chickpeas') . "\n";
            $baseInstructions .= " **" . __('basic_carbs') . ":**\n";
            $baseInstructions .= " - " . __('white_rice_staple_food') . "\n";
            $baseInstructions .= " - " . __('common_noodles_pasta') . "\n";
            $baseInstructions .= " - " . __('potato_basic_tuber') . "\n";
            $baseInstructions .= " - " . __('sweet_potato_nutritious_alternative') . "\n";
            $baseInstructions .= " - " . __('traditional_oats_not_instant') . "\n";
            $baseInstructions .= " - " . __('common_bread') . "\n";
            $baseInstructions .= " **" . __('accessible_fats') . ":**\n";
            $baseInstructions .= " - " . __('common_vegetable_oil_not_extra_virgin_olive') . "\n";
            $baseInstructions .= " - " . __('peanuts_instead_almonds') . "\n";
            $baseInstructions .= " - " . __('small_avocado_when_in_season') . "\n";
            $baseInstructions .= " **" . __('forbidden_in_low_budget') . ":**\n";
            $baseInstructions .= " " . __('salmon_tenderloin_chicken_breast_almonds_extra_virgin_olive_protein_powder') . "\n";
        } else {
            $baseInstructions = "**" . __('high_budget_premium_foods') . ":**\n";
            $baseInstructions .= " **" . __('premium_proteins') . ":**\n";
            $baseInstructions .= " - " . __('fresh_salmon_instead_basic_fish') . "\n";
            $baseInstructions .= " - " . __('tenderloin_instead_ground_beef') . "\n";
            $baseInstructions .= " - " . __('chicken_breast_premium_cut') . "\n";
            $baseInstructions .= " - " . __('fine_fish') . "\n";
            $baseInstructions .= " - " . __('protein_powder_supplementation') . "\n";
            $baseInstructions .= " - " . __('greek_yogurt_high_protein') . "\n";
            $baseInstructions .= " - " . __('fine_cheeses') . "\n";
            $baseInstructions .= " **" . __('gourmet_carbs') . ":**\n";
            $baseInstructions .= " - " . __('quinoa_andean_superfood') . "\n";
            $baseInstructions .= " - " . __('organic_oats') . "\n";
            $baseInstructions .= " - " . __('basmati_rice') . "\n";
            $baseInstructions .= " - " . __('purple_sweet_potato') . "\n";
            $baseInstructions .= " - " . __('artisanal_premium_bread') . "\n";
            $baseInstructions .= " - " . __('whole_wheat_or_legume_pasta') . "\n";
            $baseInstructions .= " **" . __('premium_fats') . ":**\n";
            $baseInstructions .= " - " . __('extra_virgin_olive_oil') . "\n";
            $baseInstructions .= " - " . __('almonds_walnuts_pistachios') . "\n";
            $baseInstructions .= " - " . __('large_hass_avocado') . "\n";
            $baseInstructions .= " - " . __('organic_coconut_oil') . "\n";
            $baseInstructions .= " - " . __('premium_seeds_chia_flax') . "\n";
            $baseInstructions .= " **" . __('gourmet_fruits') . ":**\n";
            $baseInstructions .= " - " . __('berries_blueberries_raspberries') . "\n";
            $baseInstructions .= " - " . __('imported_quality_fruits') . "\n";
            $baseInstructions .= " - " . __('organic_fruits') . "\n";
            $baseInstructions .= " - " . __('superfoods_acai_goji') . "\n";
        }

        return $baseInstructions;
    }

    private function getDetailedDietaryInstructions($dietaryStyle): string
    {
        $style = strtolower($dietaryStyle);

        if ($style === 'vegan' || $style === 'vegano') {
            return "**" . __('mandatory_vegan') . ":**\n";
            return " - " . __('only_plant_based_foods') . "\n";
            return " - " . __('proteins_legumes_tofu_seitan_quinoa_nuts_seeds') . "\n";
            return " - " . __('b12_iron_consider_supplementation') . "\n";
            return " - " . __('combine_proteins_complete_amino_acids') . "\n";
        } elseif ($style === 'vegetarian' || $style === 'vegetariano') {
            return "**" . __('mandatory_vegetarian') . ":**\n";
            return " - " . __('no_meat_no_fish') . "\n";
            return " - " . __('include_eggs_dairy_legumes_nuts') . "\n";
            return " - " . __('ensure_sufficient_iron_b12') . "\n";
        } elseif (str_contains($style, 'keto')) {
            return "**" . __('mandatory_keto') . ":**\n";
            return " - " . __('maximum_50g_net_carbs_total') . "\n";
            return " - " . __('70_fats_25_protein_5_carbs') . "\n";
            return " - " . __('prioritize_avocado_oils_nuts_meats_fatty_fish') . "\n";
            return " - " . __('avoid_grains_high_sugar_fruits_tubers') . "\n";
        }

        return "**" . __('omnivore') . ":** " . __('all_food_groups_allowed_prioritizing_variety_quality') . ".";
    }

    private function getCommunicationStyleInstructions($communicationStyle, $preferredName): string
    {
        $style = strtolower($communicationStyle);

        if (str_contains($style, 'motivational') || str_contains($style, 'motivadora')) {
            return "**" . __('motivational_communication') . ":**\n";
            return " - " . __('use_empowering_challenging_phrases') . "\n";
            return " - " . __('remember_achievements_capabilities') . "\n";
            return " - " . __('focus_on_progress_overcoming') . "\n";
            return " - " . __('energetic_tone_example', ['name' => $preferredName]) . "\n";
        } elseif (str_contains($style, 'close') || str_contains($style, 'cercana')) {
            return "**" . __('close_communication') . ":**\n";
            return " - " . __('friendly_understanding_tone') . "\n";
            return " - " . __('use_name_frequently') . "\n";
            return " - " . __('share_tips_like_a_friend') . "\n";
            return " - " . __('warm_tone_example', ['name' => $preferredName]) . "\n";
        } elseif (str_contains($style, 'direct') || str_contains($style, 'directa')) {
            return "**" . __('direct_communication') . ":**\n";
            return " - " . __('clear_concise_information') . "\n";
            return " - " . __('no_beat_around_the_bush') . "\n";
            return " - " . __('specific_data_concrete_actions') . "\n";
            return " - " . __('direct_tone_example', ['name' => $preferredName]) . "\n";
        }

        return "**" . __('adaptive_communication') . ":** " . __('mix_all_styles_versatile') . ".";
    }

    private function getCountrySpecificFoods($country, $budget): string
    {
        $countryLower = strtolower($country);
        $budgetLower = strtolower($budget);

        $budgetFoodMatrix = [
            'low' => [
                'proteins' => __('whole_egg_canned_tuna_chicken_breast_fresh_cheese_local_fish_ground_beef'),
                'carbohydrates' => __('quinoa_lentils_beans_sweet_potato_potato_white_rice_noodles_oats_corn_tortilla_whole_wheat_bread'),
                'fats' => __('peanuts_homemade_peanut_butter_sesame_seeds_olives_olive_oil')
            ],
            'high' => [
                'proteins' => __('egg_whites_whole_egg_protein_powder_high_protein_greek_yogurt_premium_chicken_breast_turkey_breast_lean_beef_fresh_salmon_fresh_sole'),
                'carbohydrates' => __('quinoa_lentils_beans_sweet_potato_potato_white_rice_noodles_oats_corn_tortilla_whole_wheat_bread'),
                'fats' => __('extra_virgin_olive_oil_avocado_oil_hass_avocado_almonds_walnuts_pistachios_pecans_organic_chia_seeds_organic_flaxseed_peanut_butter_honey_dark_chocolate_70')
            ]
        ];

        $budgetLevel = str_contains($budgetLower, 'low') || str_contains($budgetLower, 'bajo') ? 'low' : 'high';
        $foods = $budgetFoodMatrix[$budgetLevel];

        return "**" . __('specific_ingredients_from_:country_upper', ['country' => strtoupper($country)]) . ":**\n" . __('proteins_:proteins', ['proteins' => $foods['proteins']]) . "\n" . __('carbohydrates_:carbs', ['carbs' => $foods['carbohydrates']]) . "\n" . __('fats_:fats', ['fats' => $foods['fats']]);
    }
}