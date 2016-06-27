<?php
/**
 * Created by Goodizer.
 * User: Alexey Veretelnik
 * Date: 19.04.2016
 * Time: 13:16
 */

namespace goodizer\helpers;

use yii\db\ActiveRecord;

class ModelHelper
{
    /**
     * Multiple saving models with levels
     *
     * Example:
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
     *                Yii::$app->session->setFlash('success', Yii::t('system', 'save success'));
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

        $records = array();
        $levels = array();

        $defaultCallback = null;

        for ($i = 1, $lnI = count($args); $i < $lnI; $i++) {

            if ($i + 1 == $lnI && is_callable($args[$i])) {
                $defaultCallback = $args[$i];
                break;
            }

            $levelModels = $args[$i];
            $lnJ = count($levelModels);

            if (is_callable($levelModels[$lnJ - 1])) {
                $levels[$i]['callback'] = $levelModels[$lnJ - 1];
                $lnJ--;
            };

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