<?php
namespace Umutphp\LaravelModelRecommendation;

use Umutphp\LaravelModelRecommendation\RecommendationsModel;
use Illuminate\Support\Facades\DB;

/**
 * Trait class
 */
trait HasRecommendation
{
    /**
     * Generate recommendations and save it to the table. The table should be generated by using the migration.
     * The function uses co-occurrence of models in data table under same group to make a recommendation.
     *
     * @return void
     */
    public static function generateRecommendations()
    {
        $config     = self::getRecommendationConfig();

        $data = DB::table($config['recommendation_data_table'])
            ->select(
                $config['recommendation_group_field'] . ' as group_field',
                $config['recommendation_data_field'] . ' as data_field'
            );

        if (is_array($config['recommendation_data_table_filter'])) {
            foreach ($config['recommendation_data_table_filter'] as $field => $value) {
                $data = $data->where($field, $value);
            }
        }

        $data = $data->get();

        $count = $config['recommendation_count']?? config('laravel_model_recommendation.recommendation_count');

        $recommendations = self::calculateRecommendations($data, $count);

        foreach ($recommendations as $data1 => $data) {
            RecommendationsModel::where('source_type', self::class)->where('source_id', $data1)->delete();

            foreach ($data as $data2 => $order) {
                $recommendation = new RecommendationsModel(
                    [
                        'source_type'  => self::class,
                        'source_id'    => $data1,
                        'target_type'  => $config['recommendation_data_field_type'] ?? self::class,
                        'target_id'    => $data2,
                        'order_column' => $order
                    ]
                );
                $recommendation->save();
            }
        }
    }

    /**
     * Calculate recommendations
     *
     * @param Collection $data
     * @param int        $dataCount
     *
     * @return Collection
     */
    public static function calculateRecommendations($data, $dataCount)
    {
        $dataCartesianRanks = [];
        $recommendations    = [];
        $dataGroup          = [];

        foreach ($data as $value) {
            if (!isset($dataGroup[$value->group_field])) {
                $dataGroup[$value->group_field] = [];
            }

            $dataGroup[$value->group_field][$value->data_field] = $value->data_field;
        }
        
        foreach ($dataGroup as $group) {
            foreach ($group as $data1) {
                foreach ($group as $data2) {
                    if ($data1 == $data2) {
                        continue;
                    }
                    
                    if (!isset($dataCartesianRanks[$data1])) {
                        $dataCartesianRanks[$data1] = [];
                    }

                    if (!isset($dataCartesianRanks[$data1][$data2])) {
                        $dataCartesianRanks[$data1][$data2] = 0;
                    }

                    $dataCartesianRanks[$data1][$data2] += 1;
                }
            }
        }

        // Generate recommendation list by sorting
        foreach ($dataCartesianRanks as $data1 => $data) {
            arsort($data);

            $data                    = array_slice($data, 0, $dataCount, true);
            $recommendations[$data1] = $data;
        }

        return $recommendations;
    }

    /**
     * Return the list of recommended models
     *
     * @return Collection
     */
    public function getRecommendations()
    {
        $config          = self::getRecommendationConfig();
        $recommendations = RecommendationsModel::where('source_type', self::class)
            ->where('target_type', self::class)
            ->where('source_id', $this->id)
            ->get();

        $return = collect();

        foreach ($recommendations as $recommendation) {
            $model  = app($recommendation->target_type);
            $target = $model->where('id', $recommendation->target_id)->first();

            $return->push($target);
        }

        $order = $config['recommendation_order']?? config('laravel_model_recommendation.recommendation_count');

        if ($order == 'asc') {
            return $return->reverse();
        }

        if ($order == 'random') {
            $random = $return->shuffle();

            return $random->all();
        }

        return $return;
    }
}
