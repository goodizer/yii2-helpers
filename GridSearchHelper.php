<?php
/**
 * Created by Goodizer.
 * User: Alexey Veretelnik
 * Date: 19.04.2016
 * Time: 13:16
 */

namespace goodizer\helpers;

use yii\base\Component;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\db\Schema;
use yii\helpers\ArrayHelper;

class GridSearchHelper
{
    /**
     * Example:
     *
     * /* $model = new User();
     * $model->status = User::STATUS_ACTIVE;
     * $searchData = ModelHelper::search($model);
     *
     * echo \yii\grid\GridView::widget(
     *     'filterModel' => $searchData->filterModel,
     *     'dataProvider' => $searchData->dataProvider,
     *     'columns' => [
     *         ...
     *     ]
     * );
     *
     *
     * @param string | ActiveRecord $model
     * @param array $opts
     * @return SearchData | array
     */
    static function search($model, $opts = [])
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $opts = ArrayHelper::merge([
            'pagination' => ['defaultPageSize' => 10],
            'data' => null,
            'query' => null,
            'scenario' => null,
            'asArray' => false,
        ],
            $opts
        );

        if ($opts['scenario']) {
            $model->setScenario($opts['scenario']);
        }

        $result = [
            'filterModel' => $model,
            'dataProvider' => new ActiveDataProvider(array_filter([
                'query' => static::searchQuery($model, $opts),
                'pagination' => $opts['pagination'],
            ]))
        ];

        return $opts['asArray'] ? $result : (object)$result;
    }

    /**
     * @param ActiveRecord $model
     * @param array $opts
     * @return ActiveQuery | array
     */
    static function searchQuery($model, $opts = [])
    {
        $opts = ArrayHelper::merge([
            'data' => null,
            'query' => null,
            'columns' => [],
            'filters' => []
        ],
            $opts
        );

        $columns = $opts['columns'];
        $filters = $opts['filters'];
        $data = $opts['data'];
        if (null === $data) {
            $data = \Yii::$app->request->get();
        }

        $query = $opts['query'];
        if (is_string($query)) {
            $query = call_user_func([$model, $opts['query']]);
        } elseif (null === $query) {
            $query = $model->find();

            foreach (array_filter($model->getAttributes()) as $prop => $val) {
                $query->andWhere([$prop => $val]);
            }
        }

        if ($model->load($data) && $model->validate()) {
            foreach ($model->getAttributes($model->safeAttributes()) as $name => $value) {
                if ($model->isAttributeChanged($name)) {
                    $attributeTypes = [];

                    if (method_exists($model, 'attributeTypes')) {
                        $attributeTypes = $model->attributeTypes();
                    }

                    $type = null;
                    if (isset($attributeTypes[$name])) {
                        $type = $attributeTypes[$name];
                    }

                    // Default filter function
                    $filterFunc = isset($filters[$name]) && is_callable($filters[$name])
                        ? $filters[$name]
                        : function (ActiveQuery $query, $name, $value, $type) {
                            /**
                             * @var string $name
                             * @var string|array $value
                             * @var string $type
                             */
                            $query->andFilterWhere(
                                static::searchAttribute($name, $value, $type)
                            );
                        };

                    if (isset($columns[$name])) {
                        $name = $columns[$name];
                    }

                    call_user_func($filterFunc, $query, $name, $value, $type);
                }
            }
        }
        return $query;
    }

    /**
     * @param $name
     * @param $value
     * @param $type
     * @return array
     */
    static function searchAttribute($name, $value, $type = 'integer')
    {
        $op = '';
        if (!is_array($value)) {
            if (in_array($type, [Schema::TYPE_DATE, Schema::TYPE_DATETIME, Schema::TYPE_TIMESTAMP])) {
                $values = explode(' - ', $value);

                if (sizeof($values) == 1) {
                    $values = explode('+-+', $value);
                }

                if (sizeof($values) == 2) {
                    $at = strtotime($values[0]);
                    $bt = strtotime($values[1]);
                    $f = $type == Schema::TYPE_DATETIME ? 'Y-m-d H:i:s' : 'Y-m-d';
                    $values[0] = date($f, $at);
                    $values[1] = date($f, $bt);

                    if (($ad = date('Y-m-d', $at)) == ($bd = date('Y-m-d', $bt))) {
                        $values[0] = $ad . ' 00:00:00';
                        $values[1] = $bd . ' 23:59:59';
                    }

                    return ['BETWEEN', static::convertColumnToType($name, $type), $values[0], $values[1]];
                } else {
                    if (date('H:i:s', strtotime($value)) == '00:00:00') {
                        return ['=', new Expression("DATE({$name})"), $value];
                    }

                    $op = '=';
                }
            } elseif (preg_match('/^(?:\s*(<>|<=|>=|<|>|=|~))?(.*)$/', $value, $matches)) {
                $value = $matches[2];

                if (is_numeric($value)) {
                    $value = (int)$value;
                    $type = 'integer';
                }

                $op = $matches[1];
                if (!empty($op)) {
                    if ($op == '~') {
                        $values = explode('-', $value);
                        if (sizeof($values) == 2) {
                            return ['BETWEEN', static::convertColumnToType($name, $type), $values[0], $values[1]];
                        } else {
                            $op = '=';
                        }
                    }
                    return [$op, static::convertColumnToType($name, $type), $value];
                }
            }
        }
        if (!$op && preg_match('/_id$/', $name)) {
            $op = 'IN';
        }
        if (!$op) {
            if ($type == Schema::TYPE_INTEGER) {
                $op = '=';
            } else {
                $op = 'LIKE';
            }
        }
        return [$op, $name, $value];
    }

    /**
     * @TODO Add more types
     *
     * @param $name
     * @param $type
     * @return bool|Expression
     */
    static function convertColumnToType($name, $type)
    {
        if (in_array($type, [Schema::TYPE_INTEGER])) {
            return \Yii::$app->getDb()->schema instanceof \yii\db\pgsql\Schema
                ? new Expression('CAST(' . $name . ' AS INTEGER)')
                : new Expression('CAST(' . $name . ' AS UNSIGNED)');
        }

        return new Expression($name);
    }
}

class SearchData extends Component
{
    /**
     * @var ActiveRecord
     */
    public $filterModel;
    /**
     * @var ActiveDataProvider
     */
    public $dataProvider;

}