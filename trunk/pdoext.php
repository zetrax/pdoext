<?php
/**
  * A few extensions to the core PDO class.
  * Adds a few helpers and patches differences between sqlite and mysql.
  * @license LGPL
  */
class PdoExt extends PDO
{
  protected $inTransaction = FALSE;

  protected $nameOpening;
  protected $nameClosing;

  public function __construct($dsn, $user, $password, $failSafe = TRUE) {
    try {
       parent::__construct($dsn, $user, $password);
       $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
       $this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, TRUE);
    } catch (PDOException $ex) {
      if ($failSafe) {
        die("Database connection failed: " . $ex->getMessage() . " in file ".__FILE__." at line ".__LINE__);
      } else {
        throw $ex;
      }
    }
    switch ($this->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $this->nameOpening = $this->nameClosing = '`';
        break;

      case 'mssql':
        $this->nameOpening = '[';
        $this->nameClosing = ']';
        break;

      case 'sqlite':
        $this->sqliteCreateAggregate(
          "group_concat",
          Array($this, '__sqlite_group_concat_step'),
          Array($this, '__sqlite_group_concat_finalize'),
          2
        );
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, Array('PdoExt_SQLiteStatement'));
        // fallthru

      default:
        $this->nameOpening = $this->nameClosing = '"';
        break;
    }
  }

  function __sqlite_group_concat_step($context, $idx, $string, $separator = ",") {
    return ($context) ? ($context . $separator . $string) : $string;
  }

  function __sqlite_group_concat_finalize($context) {
    return $context;
  }

  /**
    * Prepares a query, binds parameters, and executes it.
    * If you're going to run the query multiple times, it's faster to prepare once, and reuse the statement.
    */
  public function pexecute($sql, $input_params = NULL) {
    $stmt = $this->prepare($sql);
    if (is_array($input_params)) {
      $stmt->execute($input_params);
    } else {
      $stmt->execute();
    }
    return $stmt;
  }

  /**
    * Returns true if a transaction has been started, and not yet finished.
    * @returns boolean
    */
  public function inTransaction() {
    return !! $this->inTransaction;
  }

  /**
    * Throws an exception if a transaction hasn't been started
    */
  public function assertTransaction() {
    if (!$this->inTransaction()) {
      throw new PdoExt_NoTransactionStartedException();
    }
  }

  /**
    * Like PDO::beginTransaction(), but throws an exception, if a transaction is already started.
    */
  public function beginTransaction() {
    if ($this->inTransaction) {
      throw new PdoExt_AlreadyInTransactionException(sprintf("Already in transaction. Tansaction started at line %s in file %s", $this->inTransaction[0], $this->inTransaction[1]));
    }
    $result = parent::beginTransaction();
    $stack = debug_backtrace();
    $this->inTransaction = Array($stack[0]['file'], $stack[0]['line']);
    return $result;
  }

  public function rollback() {
    $result = parent::rollback();
    $this->inTransaction = FALSE;
    return $result;
  }

  public function commit() {
    $result = parent::commit();
    $this->inTransaction = FALSE;
    return $result;
  }

  /**
    * Escapes names (tables, columns etc.)
    */
  public function quoteName($name) {
    $names = Array();
    foreach (explode(".", $name) as $name) {
      $names[] = $this->nameOpening
        .str_replace($this->nameClosing, $this->nameClosing.$this->nameClosing, $name)
        .$this->nameClosing;
    }
    return implode(".", $names);
  }

  /**
    * Escapes a like-lauses accordin to the rdbms syntax.
    */
  public function escapeLike($value, $wildcart = "*") {
    return str_replace($wildcart, "%", $this->quote($value));
  }

  /**
    * Returns reflection information about a table.
    */
  public function getTableMeta($table) {
    switch ($this->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $result = $this->query("SHOW COLUMNS FROM ".$this->quoteName($table));
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = Array();
        foreach ($result as $row) {
          $meta[$row['Field']] = Array(
            'pk' => $row['Key'] == 'PRI',
            'type' => $row['Type'],
          );
        }
        return $meta;
      case 'sqlite':
        $result = $this->query("PRAGMA table_info(".$this->quoteName($table).")");
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = Array();
        foreach ($result as $row) {
          $meta[$row['name']] = Array(
            'pk' => $row['pk'] == '1',
            'type' => $row['type'],
          );
        }
        return $meta;
      default:
        throw new PdoExt_MetaNotSupportedException();
    }
  }
}

/**
  * Workaround for a bug in sqlite:
  *   http://www.sqlite.org/cvstrac/tktview?tn=2378
  */
class PdoExt_SQLiteStatement extends PDOStatement
{
  protected function fixQuoteBug($hash) {
    $result = Array();
    foreach ($hash as $key => $value) {
      if (strpos($key, '"') === 0) {
        $result[substr($key, 1, -1)] = $value;
      } else {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  function fetch($fetch_style = PDO::FETCH_BOTH, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 1) {
    return $this->fixQuoteBug(parent::fetch($fetch_style, $cursor_orientation, $cursor_offset));
  }

  function fetchAll($fetch_style = PDO::FETCH_BOTH) {
    return array_map(Array($this, 'fixQuoteBug'), parent::fetchAll($fetch_style));
  }
}

class PdoExt_NoTransactionStartedException extends Exception
{
  function __construct($message = "No transaction started", $code = 0) {
    parent::__construct($message, $code);
  }
}

class PdoExt_AlreadyInTransactionException extends Exception {}

class PdoExt_MetaNotSupportedException extends Exception
{
  function __construct($message = "Meta querying not available for driver type", $code = 0) {
    parent::__construct($message, $code);
  }
}
