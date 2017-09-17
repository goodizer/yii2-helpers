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
            if(is_integer($key)) {
                $alias = '@' . str_replace('\\', '/', $nameSpace);
                $path = Yii::getAlias($alias);
            } else {
                $path = $key;
            }

            if(!is_dir($path)) {
                if($this->_isConsole) {
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
                 * @var $model ActiveRecord
                 */
                $model = new $modelCls();

                if (!$model instanceof ActiveRecord) {
                    break;
                }

                if (!method_exists($model, 'attributeTypes')) {
                    if($this->_isConsole) {
                        echo 'Required method "' . get_class($model) . '::attributeTypes()" not found.';
                    }
                    break;
                }

                $tblName = $model->tableName();
                $fieldTypes = $model->attributeTypes();
                $schema = $this->_db->getTableSchema($tblName, true);

                $fullTblName = $schema ? $schema->fullName : null;

                if (null !== $fullTblName && in_array($fullTblName, $this->tableNames)) {
                    $currColNames = $schema->getColumnNames();
                    $newColumns = array_diff(array_keys($fieldTypes), $currColNames);
                    $removeColumns = array_diff($currColNames, array_keys($fieldTypes));

                    if (!empty($newColumns)) {
                        if($this->_isConsole) {
                            echo 'Add new column(s) to the table "' . $fullTblName . '"' . PHP_EOL;
                        }
                        foreach ($newColumns as $colName) {
                            $command->addColumn($tblName, $colName, $fieldTypes[$colName]);
                            $command->execute();
                            if($this->_isConsole) {
                                echo '  Column "' . $colName . '" added with type [' . $fieldTypes[$colName] . ']' . PHP_EOL;
                            }
                        }
                        $changed = true;
                        if($this->_isConsole) {
                            echo 'Done.' . PHP_EOL . PHP_EOL;
                        }
                    }

                    if (!empty($removeColumns)) {
                        if($this->_isConsole) {
                            echo 'Remove column(s) from the table "' . $fullTblName . '"' . PHP_EOL;
                        }
                        foreach ($removeColumns as $colName) {
                            $command->dropColumn($tblName, $colName);
                            $command->execute();
                            if($this->_isConsole) {
                                echo '  Column "' . $colName . '" is removed' . PHP_EOL;
                            }
                        }
                        $changed = true;
                        if($this->_isConsole) {
                            echo 'Done.' . PHP_EOL . PHP_EOL;
                        }
                    }

                } else {
                    $command = $this->_db->createCommand();
                    $command->createTable($tblName, $fieldTypes);
                    $command->execute();
                    $changed = true;
                    if($this->_isConsole) {
                        echo 'New table "' . trim($this->_db->quoteSql($tblName), '`') . '" is created.' . PHP_EOL;
                    }
                }

            }
        }
        if (!$changed) {
            if($this->_isConsole) {
                echo 'Changes not found.' . PHP_EOL;
            }
        }
    }
}