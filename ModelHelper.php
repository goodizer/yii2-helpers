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

class ModelHelper
{
    /**
     * @param string | ActiveRecord $model
     * @param array $opts
     * @return GridSearchData | array
     */
    static function search($model, $opts = [])
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $opts = ArrayHelper::merge([
                'pagination' => null,
                'data' => null,
                'query' => null,
                'scenario' => null,
                'asArray' => true,
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
            if (in_array($type, [Schema::TYPE_DATE, Schema::TYPE_DATETIME])) {
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

    /**
     * Множественное уровнезависимое сохранение моделей
     *
     * Пример использования:
     *    $records = ActiveRecord::multiSave(
     *        $_POST,
     *        array(
     *            array(
     *                'name' => 'model',
     *                'class' => get_class($model),
     *                'record' => $model,
     *            ),
     *            function ($records) {
     *                foreach ($records['modelsIssue'] as $issue) {
     *                    $issue->projectId = $records['model']->id;
     *                }
     *            }
     *        ),
     *        array(
     *            array(
     *                'name' => 'modelsIssue',
     *                'class' => get_class(Issue::model()),
     *                'records' => $model->isNewRecord ? array(new Issue) : array(),
     *            ),
     *            function ($records) use ($ctrl) {
     *                Yii::$app->session->setFlash('success', 'Информация сохранена.');
     *                $ctrl->redirect($ctrl->createUrl('index'));
     *            }
     *        )
     *    );
     *    $this->render('_form', $records);
     *
     * @param array $data
     * @return array
     */
    static function multiSave($data = array())
    {
        $validator = !empty($data);
        $args = func_get_args();

        $status = $validator;

        // Результирующий список объектов
        $records = array();
        $levels = array();

        // Функция срабатывающая по умолчанию, т.е. в двух случаях: данные не переданы, данные не валидны
        $defaultCallback = null;

        // Проходим уровни зависимости
        for ($i = 1, $lnI = count($args); $i < $lnI; $i++) {

            // Если последний аргумент функция
            if ($i + 1 == $lnI && is_callable($args[$i])) {
                $defaultCallback = $args[$i];
                break;
            }

            // Список моделей текущего уровня
            $levelModels = $args[$i];
            $lnJ = count($levelModels);

            // Проверяем есть ли callback для текущего уровня
            if (is_callable($levelModels[$lnJ - 1])) {
                $levels[$i]['callback'] = $levelModels[$lnJ - 1];
                $lnJ--;
            };

            // Обрабатываем один уровень моделей
            for ($j = 0; $j < $lnJ; $j++) {

                $attributes = null;
                $opts = $levelModels[$j];
                if (!isset($opts['saved'])) {
                    $opts['saved'] = true;
                }

                $scenario = '';
                if (isset($opts['scenario'])) {
                    $scenario = $opts['scenario'];
                }
                /** @var ActiveRecord $record */
                $record = null;

                if (isset($opts['record'])) {
                    /** @var ActiveRecord $record */
                    $record = empty($opts['record']) ? new $opts['class'] : $opts['record'];
                    if ($scenario) {
                        $record->setScenario($scenario);
                    }

                    $reflector = new \ReflectionClass($opts['class']);
                    $cls = $reflector->getShortName();

                    if ($validator && $opts['saved']) {
                        if (isset($data[$cls])) {
                            $record->setAttributes($data[$cls]);
                            if (!$record->validate($attributes)) {
                                $status = false;
                            }
                        }
                    } else {
                        if (isset($_GET[$cls])) {
                            $record->setAttributes($_GET[$cls]);
                        }
                    }

                    $levels[$i]['records'][] = array(
                        'record' => $record,
                        'saved' => $opts['saved'],
                        'attributes' => isset($opts['attributes']) ? $attributes = $opts['attributes'] : null,
                    );

                } elseif (isset($opts['records'])) {

                    /** @var ActiveRecord[] $record */
                    $record = array();

                    $reflector = new \ReflectionClass($opts['class']);
                    $cls = $reflector->getShortName();

                    if ($validator && $opts['saved']) {

                        if (isset($data[$cls])) {
                            foreach ($data[$cls] as $name => $recordData) {
                                /** @var ActiveRecord $recordItem */
                                $recordItem = empty($opts['records'][$name]) ? new $opts['class'] : $opts['records'][$name];
                                if ($scenario) {
                                    $recordItem->setScenario($scenario);
                                }
                                $recordItem->setAttributes($recordData);
                                if (!$recordItem->validate($attributes)) {
                                    $status = false;
                                }
                                $record[] = $recordItem;
                                $levels[$i]['records'][] = array(
                                    'record' => $recordItem,
                                    'saved' => $opts['saved'],
                                    'attributes' => isset($opts['attributes']) ? $attributes = $opts['attributes'] : null,
                                );

                            }
                        }
                    } else {

                        foreach ($opts['records'] as $recordItem) {
                            /** @var ActiveRecord $recordItem */
                            if ($scenario) {
                                $recordItem->setScenario($scenario);
                            }
                            if (isset($_GET[$cls])) {
                                $recordItem->setAttributes($_GET[$cls]);
                            }
                            $record[] = $recordItem;
                            $levels[$i]['records'][] = array(
                                'record' => $recordItem,
                                'saved' => $opts['saved'],
                                'attributes' => isset($opts['attributes']) ? $attributes = $opts['attributes'] : null,
                            );
                        }

                    }
                }
                $records[$opts['name']] = $record;
            }
        }

        if ($status) {
            // Проходим уровени зависимости
            foreach ($levels as $level) {
                if (!empty($level['records'])) {
                    foreach ($level['records'] as $params) {
                        if (true == $params['saved']) {
                            /** @var ActiveRecord $record */
                            $record = $params['record'];
                            if (!$record->save(false, $params['attributes'])) {
                                break;
                            }
                        }
                    }
                }
                if (isset($level['callback'])) {
                    if (false === call_user_func($level['callback'], $records)) {
                        return $records;
                    }
                }
            }
        } elseif ($defaultCallback) {
            call_user_func($defaultCallback, $records, $validator);
        }

        return $records;
    }
}

class GridSearchData extends Component
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