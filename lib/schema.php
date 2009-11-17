<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Database schema utilities
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Database
 * @package   StatusNet
 * @author    Evan Prodromou <evan@status.net>
 * @copyright 2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

/**
 * Class representing the database schema
 *
 * A class representing the database schema. Can be used to
 * manipulate the schema -- especially for plugins and upgrade
 * utilities.
 *
 * @category Database
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class Schema
{
    static $_single = null;
    protected $conn = null;

    /**
     * Constructor. Only run once for singleton object.
     */

    protected function __construct()
    {
        // XXX: there should be an easier way to do this.
        $user = new User();

        $this->conn = $user->getDatabaseConnection();

        $user->free();

        unset($user);
    }

    /**
     * Main public entry point. Use this to get
     * the singleton object.
     *
     * @return Schema the (single) Schema object
     */

    static function get()
    {
        if (empty(self::$_single)) {
            self::$_single = new Schema();
        }
        return self::$_single;
    }

    /**
     * Returns a TableDef object for the table
     * in the schema with the given name.
     *
     * Throws an exception if the table is not found.
     *
     * @param string $name Name of the table to get
     *
     * @return TableDef tabledef for that table.
     */

    public function getTableDef($name)
    {
        $res =& $this->conn->query('DESCRIBE ' . $name);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        $td = new TableDef();

        $td->name    = $name;
        $td->columns = array();

        $row = array();

        while ($res->fetchInto($row, DB_FETCHMODE_ASSOC)) {

            $cd = new ColumnDef();

            $cd->name = $row['Field'];

            $packed = $row['Type'];

            if (preg_match('/^(\w+)\((\d+)\)$/', $packed, $match)) {
                $cd->type = $match[1];
                $cd->size = $match[2];
            } else {
                $cd->type = $packed;
            }

            $cd->nullable = ($row['Null'] == 'YES') ? true : false;
            $cd->key      = $row['Key'];
            $cd->default  = $row['Default'];
            $cd->extra    = $row['Extra'];

            $td->columns[] = $cd;
        }

        return $td;
    }

    /**
     * Gets a ColumnDef object for a single column.
     *
     * Throws an exception if the table is not found.
     *
     * @param string $table  name of the table
     * @param string $column name of the column
     *
     * @return ColumnDef definition of the column or null
     *                   if not found.
     */

    public function getColumnDef($table, $column)
    {
        $td = $this->getTableDef($table);

        foreach ($td->columns as $cd) {
            if ($cd->name == $column) {
                return $cd;
            }
        }

        return null;
    }

    /**
     * Creates a table with the given names and columns.
     *
     * @param string $name    Name of the table
     * @param array  $columns Array of ColumnDef objects
     *                        for new table.
     *
     * @return boolean success flag
     */

    public function createTable($name, $columns)
    {
        $uniques = array();
        $primary = array();
        $indices = array();

        $sql = "CREATE TABLE $name (\n";

        for ($i = 0; $i < count($columns); $i++) {

            $cd =& $columns[$i];

            if ($i > 0) {
                $sql .= ",\n";
            }

            $sql .= $this->_columnSql($cd);

            switch ($cd->key) {
            case 'UNI':
                $uniques[] = $cd->name;
                break;
            case 'PRI':
                $primary[] = $cd->name;
                break;
            case 'MUL':
                $indices[] = $cd->name;
                break;
            }
        }

        if (count($primary) > 0) { // it really should be...
            $sql .= ",\nconstraint primary key (" . implode(',', $primary) . ")";
        }

        foreach ($uniques as $u) {
            $sql .= ",\nunique index {$name}_{$u}_idx ($u)";
        }

        foreach ($indices as $i) {
            $sql .= ",\nindex {$name}_{$i}_idx ($i)";
        }

        $sql .= "); ";

        $res =& $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Drops a table from the schema
     *
     * Throws an exception if the table is not found.
     *
     * @param string $name Name of the table to drop
     *
     * @return boolean success flag
     */

    public function dropTable($name)
    {
        $res =& $this->conn->query("DROP TABLE $name");

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Adds an index to a table.
     *
     * If no name is provided, a name will be made up based
     * on the table name and column names.
     *
     * Throws an exception on database error, esp. if the table
     * does not exist.
     *
     * @param string $table       Name of the table
     * @param array  $columnNames Name of columns to index
     * @param string $name        (Optional) name of the index
     *
     * @return boolean success flag
     */

    public function createIndex($table, $columnNames, $name=null)
    {
        if (!is_array($columnNames)) {
            $columnNames = array($columnNames);
        }

        if (empty($name)) {
            $name = "$table_".implode("_", $columnNames)."_idx";
        }

        $res =& $this->conn->query("ALTER TABLE $table ".
                                   "ADD INDEX $name (".
                                   implode(",", $columnNames).")");

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Drops a named index from a table.
     *
     * @param string $table name of the table the index is on.
     * @param string $name  name of the index
     *
     * @return boolean success flag
     */

    public function dropIndex($table, $name)
    {
        $res =& $this->conn->query("ALTER TABLE $table DROP INDEX $name");

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Adds a column to a table
     *
     * @param string    $table     name of the table
     * @param ColumnDef $columndef Definition of the new
     *                             column.
     *
     * @return boolean success flag
     */

    public function addColumn($table, $columndef)
    {
        $sql = "ALTER TABLE $table ADD COLUMN " . $this->_columnSql($columndef);

        $res =& $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Modifies a column in the schema.
     *
     * The name must match an existing column and table.
     *
     * @param string    $table     name of the table
     * @param ColumnDef $columndef new definition of the column.
     *
     * @return boolean success flag
     */

    public function modifyColumn($table, $columndef)
    {
        $sql = "ALTER TABLE $table MODIFY COLUMN " .
          $this->_columnSql($columndef);

        $res =& $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Drops a column from a table
     *
     * The name must match an existing column.
     *
     * @param string $table      name of the table
     * @param string $columnName name of the column to drop
     *
     * @return boolean success flag
     */

    public function dropColumn($table, $columnName)
    {
        $sql = "ALTER TABLE $table DROP COLUMN $columnName";

        $res =& $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Ensures that the table that backs a given
     * Plugin_DataObject class exists.
     *
     * If the table does not yet exist, it will
     * create the table. If it does exist, it will
     * alter the table to match the column definitions.
     *
     * @param Plugin_DataObject $dataObjectClass
     *
     * @return boolean success flag
     */

    public function ensureDataObject($dataObjectClass)
    {
        $obj = new $dataObjectClass();
        $tableDef = $obj->tableDef();
        return $this->ensureTable($tableDef->name,$tableDef->columns);
    }

    /**
     * Ensures that a table exists with the given
     * name and the given column definitions.
     *
     * If the table does not yet exist, it will
     * create the table. If it does exist, it will
     * alter the table to match the column definitions.
     *
     * @param string $tableName name of the table
     * @param array  $columns   array of ColumnDef
     *                          objects for the table
     *
     * @return boolean success flag
     */

    public function ensureTable($tableName, $columns)
    {
        // XXX: DB engine portability -> toilet

        try {
            $td = $this->getTableDef($tableName);
        } catch (Exception $e) {
            if (preg_match('/no such table/', $e->getMessage())) {
                return $this->createTable($tableName, $columns);
            } else {
                throw $e;
            }
        }

        $cur = $this->_names($td->columns);
        $new = $this->_names($columns);

        $toadd  = array_diff($new, $cur);
        $todrop = array_diff($cur, $new);
        $same   = array_intersect($new, $cur);
        $tomod  = array();

        foreach ($same as $m) {
            $curCol = $this->_byName($td->columns, $m);
            $newCol = $this->_byName($columns, $m);

            if (!$newCol->equals($curCol)) {
                $tomod[] = $newCol->name;
            }
        }

        if (count($toadd) + count($todrop) + count($tomod) == 0) {
            // nothing to do
            return true;
        }

        // For efficiency, we want this all in one
        // query, instead of using our methods.

        $phrase = array();

        foreach ($toadd as $columnName) {
            $cd = $this->_byName($columns, $columnName);

            $phrase[] = 'ADD COLUMN ' . $this->_columnSql($cd);
        }

        foreach ($todrop as $columnName) {
            $phrase[] = 'DROP COLUMN ' . $columnName;
        }

        foreach ($tomod as $columnName) {
            $cd = $this->_byName($columns, $columnName);

            $phrase[] = 'MODIFY COLUMN ' . $this->_columnSql($cd);
        }

        $sql = 'ALTER TABLE ' . $tableName . ' ' . implode(', ', $phrase);

        $res =& $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Returns the array of names from an array of
     * ColumnDef objects.
     *
     * @param array $cds array of ColumnDef objects
     *
     * @return array strings for name values
     */

    private function _names($cds)
    {
        $names = array();

        foreach ($cds as $cd) {
            $names[] = $cd->name;
        }

        return $names;
    }

    /**
     * Get a ColumnDef from an array matching
     * name.
     *
     * @param array  $cds  Array of ColumnDef objects
     * @param string $name Name of the column
     *
     * @return ColumnDef matching item or null if no match.
     */

    private function _byName($cds, $name)
    {
        foreach ($cds as $cd) {
            if ($cd->name == $name) {
                return $cd;
            }
        }

        return null;
    }

    /**
     * Return the proper SQL for creating or
     * altering a column.
     *
     * Appropriate for use in CREATE TABLE or
     * ALTER TABLE statements.
     *
     * @param ColumnDef $cd column to create
     *
     * @return string correct SQL for that column
     */

    private function _columnSql($cd)
    {
        $sql = "{$cd->name} ";

        if (!empty($cd->size)) {
            $sql .= "{$cd->type}({$cd->size}) ";
        } else {
            $sql .= "{$cd->type} ";
        }

        if (!empty($cd->default)) {
            $sql .= "default {$cd->default} ";
        } else {
            $sql .= ($cd->nullable) ? "null " : "not null ";
        }

        return $sql;
    }
}

/**
 * A class encapsulating the structure of a table.
 *
 * @category Database
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class TableDef
{
    /** name of the table */
    public $name;
    /** array of ColumnDef objects for the columns. */
    public $columns;
    
    /**
     * Constructor.
     *
     * @param string  $name     name of the table
     * @param array   $columns  columns in the table
     */

    function __construct($name=null,$columns=null)
    {
        $this->name = $name;
        $this->columns = $columns;
    }
}

/**
 * A class encapsulating the structure of a column in a table.
 *
 * @category Database
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class ColumnDef
{
    /** name of the column. */
    public $name;
    /** type of column, e.g. 'int', 'varchar' */
    public $type;
    /** size of the column. */
    public $size;
    /** boolean flag; can it be null? */
    public $nullable;
    /**
     * type of key: null = no key; 'PRI' => primary;
     * 'UNI' => unique key; 'MUL' => multiple values.
     */
    public $key;
    /** default value if any. */
    public $default;
    /** 'extra' stuff. Returned by MySQL, largely
     * unused. */
    public $extra;
    /** auto increment this field if no value is specific for it during an insert **/
    public $auto_increment;

    /**
     * Constructor.
     *
     * @param string  $name     name of the column
     * @param string  $type     type of the column
     * @param int     $size     size of the column
     * @param boolean $nullable can this be null?
     * @param string  $key      type of key
     * @param value   $default  default value
     * @param value   $extra    unused
     */

    function __construct($name=null, $type=null, $size=null,
                         $nullable=true, $key=null, $default=null,
                         $extra=null, $auto_increment=false)
    {
        $this->name     = strtolower($name);
        $this->type     = strtolower($type);
        $this->size     = $size+0;
        $this->nullable = $nullable;
        $this->key      = $key;
        $this->default  = $default;
        $this->extra    = $extra;
        $this->auto_increment = $auto_increment;
    }

    /**
     * Compares this columndef with another to see
     * if they're functionally equivalent.
     *
     * @param ColumnDef $other column to compare
     *
     * @return boolean true if equivalent, otherwise false.
     */

    function equals($other)
    {
        return ($this->name == $other->name &&
                $this->_typeMatch($other) &&
                $this->_defaultMatch($other) &&
                $this->_nullMatch($other) &&
                $this->key == $other->key &&
                $this->auto_increment == $other->auto_increment);
    }

    /**
     * Does the type of this column match the
     * type of the other column?
     *
     * Checks the type and size of a column. Tries
     * to ignore differences between synonymous
     * data types, like 'integer' and 'int'.
     *
     * @param ColumnDef $other other column to check
     *
     * @return boolean true if they're about equivalent
     */

    private function _typeMatch($other)
    {
        switch ($this->type) {
        case 'integer':
        case 'int':
            return ($other->type == 'integer' ||
                    $other->type == 'int');
            break;
        default:
            return ($this->type == $other->type &&
                    $this->size == $other->size);
        }
    }

    /**
     * Does the default behaviour of this column match
     * the other?
     *
     * @param ColumnDef $other other column to check
     *
     * @return boolean true if defaults are effectively the same.
     */

    private function _defaultMatch($other)
    {
        return ((is_null($this->default) && is_null($other->default)) ||
                ($this->default == $other->default));
    }

    /**
     * Does the null behaviour of this column match
     * the other?
     *
     * @param ColumnDef $other other column to check
     *
     * @return boolean true if these columns 'null' the same.
     */

    private function _nullMatch($other)
    {
        return ((!is_null($this->default) && !is_null($other->default) &&
                 $this->default == $other->default) ||
                ($this->nullable == $other->nullable));
    }
}
