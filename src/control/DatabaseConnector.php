<?php
/**
 * Postgres Database Connector Class
 *
 * This class is based off the class I wrote and provided in the SNAC project, at:
 * https://github.com/snac-cooperative/snac/blob/master/src/snac/server/database/DatabaseConnector.php
 * Therefore, it contains the same license and copyright as the original.  The file has been modified
 * to remove the SNAC-specific error messages.
 *
 * @author Robbie Hott
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */
namespace manager\control;

use \manager\Config as Config;

/**
 * Database Connector Class
 *
 * This class provides a thin layer in front of the standard PHP Postgres library functions, so that
 * correct error handling may happen throughout the code.  
 *
 * @author Robbie Hott
 */
class DatabaseConnector {

    /**
     * @var \resource Database handle for postgres connection
     */
    private $dbHandle = null;
    
    /**
     * Constructor
     *
     * Opens the connection to the database on construct
     */
    public function __construct() {

        // Read the configuration file
        $host = Config::$DATABASE["host"];
        $port = Config::$DATABASE["port"];
        $database = Config::$DATABASE["database"];
        $password = Config::$DATABASE["password"];
        $user = Config::$DATABASE["user"];

        // Try to connect to the database
        $this->dbHandle = \pg_connect("host=$host port=$port dbname=$database user=$user password=$password");

        if ($this->dbHandle === false) {
            die("Postgres connection error");
        }

    }

    /**
     * Prepare A Statement
     *
     * Calls php postgres pg_prepare method.  The statement should be named, and the query given.
     *
     * @param string $statementName Name for the statement (allows multiple prepares)
     * @param string $query Query to prepare (with $1, $2, .. placeholders)
     */
    public function prepare($statementName, $query) {
        $result = \pg_prepare($this->dbHandle, $statementName, $query);

        // Check for error
        if ($result === false) {
            $errorMessage = \pg_last_error($this->dbHandle);
            throw new \Exception("Database Prepare Error: " . $errorMessage);
        }
    }

    /**
     * Execute a prepared database statement
     *
     * Executes the statement prepared earlier as $statementName, with the given array of values used to fill the
     * placeholders in the prepared statement.  Any values passed in the array will be converted to strings.
     *
     * @param string $statementName Statement name to execute
     * @param mixed[] $values Parameters to fill the prepared statement (will be cast to string)
     * @return \resource Postgres resource for the result
     */
    public function execute($statementName, $values) {
        $result = \pg_execute($this->dbHandle, $statementName, $values);

        // Check for error
        if ($result === false) {
            $errorMessage = \pg_last_error($this->dbHandle);
            throw new \Exception("Database Execute Error: " . $errorMessage);
        }

        $resultError = \pg_result_error($result);
        if ($resultError === false) {
            throw new \Exception("Database Execute Error: Could not return results -- malformed result");
        } else if (!empty($resultError)) {
            throw new \Exception("Database Execute Error: " . $resultError);
        }

        return $result;
    }

    /**
     * Prepare and Execute a database statement. From the php docs on prepare(): [The first argument] stmtname
     * may be "" to create an unnamed statement, in which case any pre-existing unnamed statement is
     * automatically replaced; otherwise it is an error if the statement name is already defined in the
     * current session.
     *
     * Handles both the prepare and execute stages.
     *
     * @param string $query Query to prepare (with $1, $2, .. placeholders)
     * @param mixed[] $values Parameters to fill the prepared statement (will be cast to string)
     * @return \resource Postgres resource for the result
     */
    public function query($query, $values) {
        $this->prepare("", $query);
        return $this->execute("", $values);
    }


    /**
     * Need to add some docs and perhaps throw an exception if the query exists and can't be deallocated. If
     * the query doesn't exist we don't particularly care.
     *
     * @param string $query The name of the query to deallocate. Query names are lower case strings. Things
     * break with mixed case.
     *
     * @return void
     */

    public function deallocate($query) {
        if (! pg_query($this->dbHandle, "deallocate $query"))
        {
            printf("deaollcate failed: %s\n", pg_last_error($cnx));
        }
    }

    /**
     * Fetch the next row
     *
     * Fetches the next row from the given resource and returns it as an associative array.
     *
     * @param \resource $resource Postgres result resource (From $db->execute())
     * @return string[] Next row from the database as an associative array, or false if no rows to return
     */
    public function fetchRow($resource) {
        $row = \pg_fetch_assoc($resource);
        return $row;
    }


    /**
     * Fetch all rows
     *
     * Fetches all rows from the given resource and returns it as an array of associative arrays.
     *
     * @param \resource $resource Postgres result resource (From $db->execute())
     * @return string[][] All rows from the database as an associative array, or false if no rows to return
     */
    public function fetchAll($resource) {
        $rows = \pg_fetch_all($resource);
        if ($rows == null || empty($rows))
            return [];
        return $rows;
    }

    /**
     * Get the DB Handle
     *
     * This method returns the handle to the database.   This should never be used except in scripting.
     *
     * @return \resource Database handle for postgres connection
     */
    public function getHandle() {
        return $this->dbHandle;
    }

}
