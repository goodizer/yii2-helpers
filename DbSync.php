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
class DbSync
{
    /**
     * @var Connection
     */
    private $db;

    /**
     * @var string[]
     */
    private $nameSpaces;

    /**
     * @var string[]
     */
    private $tableNames;

    /**
     * Example:
     * Namespaces
     *
     * (new DbSync([
     *     'common\models\',
     *     'frontend\models\',
     * ]))->run();
     *
     *
     * @param array $nameSpaces
     */
    public function __construct(array $nameSpaces)
    {
        foreach ($nameSpaces as $nameSpace) {
            $this->nameSpaces[] = trim($nameSpace, '\\') . '\\';
        }

        $this->db = \Yii::$app->getDb();
        $this->tableNames = $this->db->getSchema()->getTableNames();
    }

    public function run()
    {
        $command = $this->db->createCommand();
        $changed = false;

        foreach ($this->nameSpaces as $nameSpace) {
            $path = '@' . str_replace('\\', '/', $nameSpace);

            foreach (glob(\Yii::getAlias($path . '*.php')) as $file) {
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
                $tableSchema = $this->db->getTableSchema($tblName, true);

                $fullTblName = $tableSchema ? $tableSchema->fullName : null;

                if (null !== $fullTblName && in_array($fullTblName, $this->tableNames)) {
                    $currColNames = $this->db->getTableSchema($tblName, true)->getColumnNames();
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