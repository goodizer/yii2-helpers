<?php
/**
 * Db sync helper.
 * @author: Goodizer
 * @version 1.4.0
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
    const ADD_CONSTRAINT_REFERENCES = 1;

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
     * ```php
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
     *
     *          // Set new index and foreign key example
     *          // addForeignKey method params array:
     *          // [$name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null]
     *          // Warning! First arg "$name" will be auto generated
     *
     *          DbSyncHelper::ADD_CONSTRAINT_REFERENCES => [
     *              'addForeignKey' => [static::tableName(), 'owner_id', Owner::tableName(), 'id', 'CASCADE'],
     *          ],
     *      ];
     * }
     * ```
     *
     * Example of use:
     *
     * ```php
     * (new DbSync([
     *     'common\models\', // if namespace equivalent to path
     *     'Path to directory' => 'someName\models\',
     * ]))->run();
     * ```
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
                $this->debug_msg("Directory not exist: ");
                $this->debug_msg("Path - '{$path}'");
                $this->debug_msg("Namespace - '{$nameSpace}'");
                echo PHP_EOL;
                break;
            }

            foreach (glob($path . '*.php') as $k => $file) {
                $info = pathinfo($file);
                $modelCls = $nameSpace . $info['filename'];

                /**
                 * @var $model ActiveRecord|object
                 */
                $model = new $modelCls();

                if (!$model instanceof ActiveRecord || !method_exists($model, 'attributeTypes')) {
                    continue;
                }

                $tbl_name = $model->tableName();
                $schema = $this->_db->getTableSchema($tbl_name, true);
                $field_types = array_filter($model->attributeTypes(), function ($v, $k) {
                    return is_string($k) && is_string($v);
                }, ARRAY_FILTER_USE_BOTH);
                $stack_to_add = array_filter($model->attributeTypes(), function ($v, $k) {
                    return is_int($k) && is_array($v);
                }, ARRAY_FILTER_USE_BOTH);

                if (null !== $schema && in_array($tbl_full_name = $schema ? $schema->fullName : null, $this->tableNames)) {
                    $new_columns = array_filter(array_diff(array_keys($field_types), $schema->getColumnNames()));

                    if (!empty($new_columns)) {
                        $this->debug_msg("Add new column(s) to the table ':tbl_name'.", [
                            ':tbl_name' => $tbl_full_name,
                        ]);

                        foreach ($new_columns as $column) {
                            $command->addColumn($tbl_name, $column, $field_types[$column]);
                            $command->execute();

                            $this->debug_msg("  Column ':column' added with type [:type].", [
                                ':column' => $column,
                                ':type' => $field_types[$column],
                            ]);
                        }

                        $changed = true;
                        $this->debug_msg("Done.");
                        echo PHP_EOL;
                    }

                    $remove_columns = array_diff($schema->getColumnNames(), array_keys($field_types));

                    /** Remove columns */
                    if (!empty($remove_columns)) {
                        $this->debug_msg("Remove column(s) from the table ':tbl_name'.", [':tbl_name' => $tbl_full_name]);

                        foreach ($remove_columns as $column) {
                            foreach ($schema->foreignKeys as $fk => $data) {
                                if (isset($data[$column])) {
                                    $command->dropForeignKey($fk, $tbl_full_name);
                                    $command->execute();

                                    $this->debug_msg("  ForeignKey ':fk' is removed.", [':fk' => $fk]);
                                }
                            }

                            $command->dropColumn($tbl_name, $column);
                            $command->execute();

                            $this->debug_msg("  Column ':column' is removed.", [':column' => $column]);
                        }

                        $changed = true;

                        $this->debug_msg("Done.");
                        echo PHP_EOL;
                    }

                } else {
                    $command->createTable($tbl_name, $field_types);
                    $command->execute();
                    $changed = true;
                    $this->debug_msg("New table ':tbl_name' is created.", [
                        ':tbl_name' => trim($this->_db->quoteSql($tbl_name)),
                    ]);
                }

                /** Executing createIndex, addForeignKey for saved tables */
                foreach ($stack_to_add as $method => $params) {
                    switch ($method) {
                        case static::ADD_CONSTRAINT_REFERENCES:
                            list($tbl, $col, $ref_tbl, $ref_col, $delete, $update) = $params;

                            if (!$tbl || !$col || !$ref_tbl || !$ref_col) {
                                $this->debug_msg("!Missing required args for Command::addForeignKey(): :args.", [
                                    ':args' => '$tbl, $col, $ref_tbl, $ref_col'
                                ]);
                                break;
                            }

                            $idx_name = "{$tbl}_{$col}_idx_{$k}";
                            $fk_name = "{$tbl}_{$col}_fk_{$k}";

                            /** If foreign key already exist or not found in field types */
                            if (isset($schema->foreignKeys[$fk_name], $schema->foreignKeys[$fk_name][$col])
                                || !isset($field_types[$col])
                            ) {
                                break;
                            }

                            $command->createIndex($idx_name, $tbl, $col);
                            $command->execute();

                            $this->debug_msg("Create new index '{$idx_name}'.");

                            $command->addForeignKey($fk_name, $tbl, $col, $ref_tbl, $ref_col, $delete, $update);
                            $command->execute();

                            $this->debug_msg("Add new constraint fk ':column' -> ':fk_name' references to ':references'.", [
                                ':column' => $tbl . '.' . $col,
                                ':fk_name' => $fk_name,
                                ':references' => $ref_tbl . '.' . $ref_col,
                            ]);

                            break;
                    }
                }
            }
        }

        if (!$changed) {
            $this->debug_msg('Changes not found.');
        } else {
            $this->debug_msg('End.');
        }
    }

    /**
     * @param $msg
     * @param array $params
     * @param bool $return
     * @return null|string
     */
    protected function debug_msg($msg, $params = [], $return = false)
    {
        if ($this->_isConsole) {
            if (!empty($params)) {
                $msg = strtr($msg, $params);
            }
            if ($return === true) {
                return $msg;
            }
            echo $msg . "\r\n";
        }

        return null;
    }
}