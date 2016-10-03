<?php
namespace goodizer\helpers;

use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * Created by PhpStorm.
 * User: Администратор
 * Date: 04.11.2015
 * Time: 9:37
 */
class DbSyncHelper
{
    /**
     * @var Connection
     */
    private $db;

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
     */
    public function __construct(array $nameSpaces)
    {
        foreach ($nameSpaces as $key => $nameSpace) {
            $this->nameSpaces[$key] = trim($nameSpace, '\\') . '\\';
        }

        $this->db = \Yii::$app->getDb();
        $this->tableNames = $this->db->getSchema()->getTableNames();
    }

    public function run()
    {
        $command = $this->db->createCommand();
        $changed = false;

        foreach ($this->nameSpaces as $key => $nameSpace) {
            if(is_integer($key)) {
                $alias = '@' . str_replace('\\', '/', $nameSpace);
                $path = \Yii::getAlias($alias);
            } else {
                $path = $key;
            }

            if(!is_dir($path)) {
                echo 'Directory not exist' . PHP_EOL;
                echo 'Path - "' . $path . '"' . PHP_EOL;
                echo 'Namespace - "' . $nameSpace . '"' . PHP_EOL . PHP_EOL;
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
                    echo 'Required method "' . get_class($model) . '::attributeTypes()" not found.';
                    break;
                }

                $tblName = $model->tableName();
                $fieldTypes = $model->attributeTypes();
                $schema = $this->db->getTableSchema($tblName, true);

                $fullTblName = $schema ? $schema->fullName : null;

                if (null !== $fullTblName && in_array($fullTblName, $this->tableNames)) {
                    $currColNames = $schema->getColumnNames();
                    $newColumns = array_diff(array_keys($fieldTypes), $currColNames);
                    $removeColumns = array_diff($currColNames, array_keys($fieldTypes));

                    if (!empty($newColumns)) {
                        echo 'Add new column(s) to the table "' . $fullTblName . '"' . PHP_EOL;
                        foreach ($newColumns as $colName) {
                            $command->addColumn($tblName, $colName, $fieldTypes[$colName]);
                            $command->execute();
                            echo '  Column "' . $colName . '" added with type [' . $fieldTypes[$colName] . ']' . PHP_EOL;
                        }
                        $changed = true;
                        echo 'Done.' . PHP_EOL . PHP_EOL;
                    }

                    if (!empty($removeColumns)) {
                        echo 'Remove column(s) from the table "' . $fullTblName . '"' . PHP_EOL;
                        foreach ($removeColumns as $colName) {
                            $command->dropColumn($tblName, $colName);
                            $command->execute();
                            echo '  Column "' . $colName . '" is removed' . PHP_EOL;
                        }
                        $changed = true;
                        echo 'Done.' . PHP_EOL . PHP_EOL;
                    }

                } else {
                    $command = $this->db->createCommand();
                    $command->createTable($tblName, $fieldTypes);
                    $command->execute();
                    $changed = true;
                    echo 'New table "' . trim($this->db->quoteSql($tblName), '`') . '" is created.' . PHP_EOL;
                }

            }
        }
        if (!$changed) {
            echo 'Changes not found.' . PHP_EOL;
        }
    }
}