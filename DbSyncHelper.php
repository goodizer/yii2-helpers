<?php
/**
 * Db sync helper.
 * @author: Goodizer
 * @version 1.2.2
 */

namespace goodizer\helpers;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * Class DbSyncHelper
 * @package goodizer\helpers
 */
class DbSyncHelper
{
    /**
     * @var Connection
     */
    private $_db;

    /**
     * @var bool
     */
    private $_isConsole;

    /**
     * @var string[]
     */
    public $nameSpaces;

    /**
     * @var string[]
     */
    public $tableNames;

    /**
     * Important - modelClass must have a public method "attributeTypes"
     * Example:
     *
     * public function attributeTypes()
     * {
     *      return [
     *          'id' => Schema::TYPE_PK,
     *          'owner_id' => Schema::TYPE_INTEGER,
     *          'name' => Schema::TYPE_STRING,
     *          'description' => Schema::TYPE_TEXT,
     *          'status' => Schema::TYPE_SMALLINT,
     *          'updated_at' => Schema::TYPE_TIMESTAMP,
     *          'created_at' => Schema::TYPE_DATETIME,
     *          'test_category_id' => Schema::TYPE_INTEGER,
     *
     *          // Set new index and foreign key example
     *          // "addForeignKey" method params array: [table name, table field, relation table name, relation field name, on delete, on update]
     *          [
     *              'createIndex' => [static::tableName(), 'test_category_id'],
     *              'addForeignKey' => [static::tableName(), 'test_category_id', ProductCategory::tableName(), 'id', 'CASCADE'],
     *          ],
     *      ];
     * }
     *
     * Example of use:
     *
     * (new DbSync([
     *     'common\models\', // if namespace equivalent to path
     *     'Path to directory' => 'someName\models\',
     * ]))->run();
     *
     *
     * @param array $nameSpaces
     * @param null|Connection $db
     */
    public function __construct(array $nameSpaces, $db = null)
    {
        foreach ($nameSpaces as $key => $nameSpace) {
            $this->nameSpaces[$key] = trim($nameSpace, '\\') . '\\';
        }

        $this->_isConsole = Yii::$app instanceof yii\console\Application;
        $this->_db = $db ?: Yii::$app->getDb();
        $this->tableNames = $this->_db->getSchema()->getTableNames();
    }

    public function run()
    {
        $command = $this->_db->createCommand();
        $changed = false;

        foreach ($this->nameSpaces as $key => $nameSpace) {
            if (is_integer($key)) {
                $alias = '@' . str_replace('\\', '/', $nameSpace);
                $path = Yii::getAlias($alias);
            } else {
                $path = $key;
            }

            if (!is_dir($path)) {
                if ($this->_isConsole) {
                    echo 'Directory not exist' . PHP_EOL;
                    echo 'Path - "' . $path . '"' . PHP_EOL;
                    echo 'Namespace - "' . $nameSpace . '"' . PHP_EOL . PHP_EOL;
                }
                break;
            }

            foreach (glob($path . '*.php') as $file) {
                $info = pathinfo($file);
                $modelCls = $nameSpace . $info['filename'];

                /**
                 * @var $model ActiveRecord|object
                 */
                $model = new $modelCls();

                if (!$model instanceof ActiveRecord || !method_exists($model, 'attributeTypes')) {
                    continue;
                }

                $tblName = $model->tableName();
                $fieldTypes = $model->attributeTypes();
                $schema = $this->_db->getTableSchema($tblName, true);
                $funcStack = array_filter($fieldTypes, function ($v) {
                    return is_array($v);
                });
                $newColumns = array_filter($fieldTypes, function ($v) {
                    return !is_array($v);
                });

                if (null !== $schema && in_array($fullTblName = $schema ? $schema->fullName : null, $this->tableNames)) {
                    $currColNames = $schema->getColumnNames();
                    $newColumns = array_filter(array_diff(array_keys($fieldTypes), $currColNames));
                    $removeColumns = array_diff($currColNames, array_keys($fieldTypes));

                    if (!empty($newColumns)) {
                        if ($this->_isConsole) {
                            echo 'Add new column(s) to the table "' . $fullTblName . '".' . PHP_EOL;
                        }

                        foreach ($newColumns as $column) {
                            $command->addColumn($tblName, $column, $fieldTypes[$column]);
                            $command->execute();

                            if ($this->_isConsole) {
                                echo '  Column "' . $column . '" added with type [' . $fieldTypes[$column] . '].' . PHP_EOL;
                            }
                        }

                        $changed = true;
                        if ($this->_isConsole) {
                            echo 'Done.' . PHP_EOL . PHP_EOL;
                        }
                    }

                    /** Remove columns */
                    if (!empty($removeColumns)) {
                        if ($this->_isConsole) {
                            echo 'Remove column(s) from the table "' . $fullTblName . '".' . PHP_EOL;
                        }

                        foreach ($removeColumns as $column) {
                            foreach ($schema->foreignKeys as $fk => $data) {
                                if (isset($data[$column])) {
                                    $command->dropForeignKey($fk, $fullTblName);
                                    $command->execute();

                                    if ($this->_isConsole) {
                                        echo '  ForeignKey "' . $fk . '" is removed.' . PHP_EOL;
                                    }
                                }
                            }

                            $command->dropColumn($tblName, $column);
                            $command->execute();
                            if ($this->_isConsole) {
                                echo '  Column "' . $column . '" is removed.' . PHP_EOL;
                            }
                        }

                        $changed = true;
                        if ($this->_isConsole) {
                            echo 'Done.' . PHP_EOL . PHP_EOL;
                        }
                    }

                } else {
                    $command = $this->_db->createCommand();
                    $command->createTable($tblName, $newColumns);
                    $command->execute();
                    $changed = true;
                    if ($this->_isConsole) {
                        echo 'New table "' . trim($this->_db->quoteSql($tblName), '`') . '" is created.' . PHP_EOL;
                    }
                    $newColumns = array_keys($newColumns);
                }


                /** Executing createIndex, addForeignKey for saved tables */
                if (!empty($funcStack)) {
                    foreach ($funcStack as $k => $stack) {
                        foreach ($stack as $method => $params) {
                            switch ($method) {
                                case "createIndex":
                                    list($table, $column) = $params;

                                    if (!$table || !$column) {
                                        echo "      !!! Skip to execute method '{$method}'. Wrong params." . PHP_EOL;
                                        break;
                                    }

                                    if (!in_array($column, $newColumns)) {
                                        break;
                                    }

                                    call_user_func_array([$command, $method], [
                                        "{$table}_{$column}_idx_{$k}",
                                        $table,
                                        $column,
                                    ]);

                                    echo 'Create new index "' . "{$table}_{$column}_idx_{$k}" . '".';
                                    echo PHP_EOL;

                                    $command->execute();
                                    break;
                                case "addForeignKey":
                                    list($table, $column, $refTable, $refColumn, $delete, $update) = $params;

                                    if (!$table || !$column || !$refTable || !$refColumn) {
                                        echo "      !!! Skip to execute method '{$method}'. Wrong params." . PHP_EOL;
                                        break;
                                    }

                                    if (!in_array($column, $newColumns)) {
                                        break;
                                    }

                                    call_user_func_array([$command, $method], [
                                        "{$table}_{$column}_fk_{$k}",
                                        $table,
                                        $column,
                                        $refTable,
                                        $refColumn,
                                        $delete,
                                        $update,
                                    ]);

                                    echo "Add new foreign key '{$column}' ({$table}_{$column}_fk_{$k}) reference on '{$refTable}.{$refColumn}'";
                                    echo PHP_EOL;

                                    $command->execute();
                                    break;
                            }
                        }
                    }
                }
            }
        }

        if (!$changed) {
            if ($this->_isConsole) {
                echo 'Changes not found.' . PHP_EOL;
            }
        } else {
            if ($this->_isConsole) {
                echo 'End.' . PHP_EOL;
            }
        }
    }
}