<?php

/**
  *  RedBeanPHP Dynamic Facade
  *
  *  This class provides a non-static facade to RedBeanPHP.
  *  If you don't know what that means, you probably won't need this.
  *  This may or may not implement all of the functionality of the
  *  original, static facade, so use with caution. 
  *
  *  See license.txt for licensing details and example.php for a demonstration.
  *
  *  @author Marko Tukiainen
  *  @version 3.4.5
  *  
  */

namespace Aptual;

/**
 * Aptual\Database
 * @filesource    class.database.php
 * @description   A replacement of RedBean's static R facade. Allows multiple
 *                instances to be created
 * @author        Marko Tukiainen
 */
class Database {
  
	/**
	 *
	 * Constains an instance of the RedBean Toolbox
	 * @var RedBean_ToolBox
	 *
	 */
  public $toolbox;
  
	/**
	 * Constains an instance of RedBean OODB
	 * @var RedBean_OODB
	 */
  public $redbean;
  
	/**
	 * Contains an instance of the Query Writer
	 * @var RedBean_QueryWriter
	 */
  public $writer;
  
	/**
	 * Contains an instance of the Database
	 * Adapter.
	 * @var RedBean_DBAdapter
	 */
  public $adapter;
  
	/**
	 * Contains an instance of the Association Manager
	 * @var RedBean_AssociationManager
	 */
	public $associationManager;
  
	/**
	 * Contains an instance of the Extended Association Manager
	 * @var RedBean_ExtAssociationManager
	 */
	public $extAssocManager;
  
	/**
	 * Holds the tag manager
	 * @var RedBean_TagManager
	 */
	public $tagManager;
  
	/**
	 * holds the duplication manager
	 * @var RedBean_DuplicationManager 
	 */
	public $duplicationManager;

	/**
	 * Holds the Label Maker instance.
	 * This facility allows you to make label beans.
	 * @var RedBean_LabelMaker 
	 */
	public $labelMaker;
	
	/**
	 * Holds the Finder instance for the facade.
	 * @var RedBean_Finder
	 */
	public $finder;
  
	/**
	 * Holds the Key of the current database.
	 * @var string
	 */
	public $currentDB = '';
  
	/**
	 * @var boolean
	 */
	private $strictType = true;
  
	/**
	 * Holds reference to SQL Helper
	 */
	public $f;
	
	/**
	 * Holds reference to model helper
	 */
	public $modelHelper; 

	/**
	 * Get version
	 * @return string
	 */
	public function getVersion() {
    return \RedBean_Facade::getVersion(); 
	}
  
	/**
	 * This method checks the DSN string. If the DSN string contains a
	 * database name that is not supported by RedBean yet then it will
	 * throw an exception RedBean_Exception_NotImplemented. In any other
	 * case this method will just return boolean TRUE.
	 * @throws RedBean_Exception_NotImplemented
	 * @param string $dsn
	 * @return boolean $true
	 */
	private function checkDSN($dsn) {
		$dsn = trim($dsn);
		$dsn = strtolower($dsn);
		if (
		strpos($dsn, 'mysql:')!==0
				  && strpos($dsn,'sqlite:')!==0
				  && strpos($dsn,'pgsql:')!==0
				  && strpos($dsn,'cubrid:')!==0
		) {
			trigger_error('Unsupported DSN');
		}
		else {
			return true;
		}
	}

	/**
	 * Kickstarts redbean for you. This method should be called before you start using
	 * RedBean. The Setup() method can be called without any arguments, in this case it will
	 * try to create a SQLite database in /tmp called red.db (this only works on UNIX-like systems).
	 *
	 * @param string $dsn      Database connection string
	 * @param string $username Username for database
	 * @param string $password Password for database
	 *
	 * @return void
	 */
	public function setup($dsn=NULL, $username=NULL, $password=NULL,$frozen=false) {
		if (function_exists('sys_get_temp_dir')) $tmp = sys_get_temp_dir(); else $tmp = 'tmp';
		if (is_null($dsn)) $dsn = 'sqlite:/'.$tmp.'/red.db';
    
		if ($dsn instanceof \PDO) {
			$pdo = new \RedBean_Driver_PDO($dsn);
			$dsn = $pdo->getDatabaseType() ;
		}
		else {
			$this->checkDSN($dsn);
			$pdo = new \RedBean_Driver_PDO($dsn,$username,$password);
		}
		$adapter = new \RedBean_Adapter_DBAdapter($pdo);
		if (strpos($dsn,'pgsql')===0) {
			$writer = new \RedBean_QueryWriter_PostgreSQL($adapter);
		}
		else if (strpos($dsn,'sqlite')===0) {
			$writer = new \RedBean_QueryWriter_SQLiteT($adapter);
		}
		else if (strpos($dsn,'cubrid')===0) {
			$writer = new \RedBean_QueryWriter_Cubrid($adapter);
		}
		else {
			$writer = new \RedBean_QueryWriter_MySQL($adapter);
		}
		$redbean = new \RedBean_OODB($writer, $this);
		if ($frozen) $redbean->freeze(true);
    $this->toolbox = new \RedBean_ToolBox($redbean,$adapter,$writer);
    $this->configureFacadeWithToolbox($this->toolbox);
		return $this->toolbox;
	}
  
	/**
	 * Starts a transaction within a closure (or other valid callback).
	 * If an Exception is thrown inside, the operation is automatically rolled back.
	 * If no Exception happens, it commits automatically.
	 * It also supports (simulated) nested transactions (that is useful when 
	 * you have many methods that needs transactions but are unaware of
	 * each other).
	 * ex:
	 * 		$from = 1;
	 * 		$to = 2;
	 * 		$ammount = 300;
	 * 		
	 * 		R::transaction(function() use($from, $to, $ammount)
	 * 	    {
	 * 			$accountFrom = R::load('account', $from);
	 * 			$accountTo = R::load('account', $to);
	 * 			
	 * 			$accountFrom->money -= $ammount;
	 * 			$accountTo->money += $ammount;
	 * 			
	 * 			R::store($accountFrom);
	 * 			R::store($accountTo);
	 *      });
	 * 
	 * @param callable $callback Closure (or other callable) with the transaction logic
	 * 
	 * @return void
	 */
	public function transaction($callback) {
		if (!is_callable($callback)) throw new InvalidArgumentException('transaction() needs a valid callback.');
		static $depth = 0;
		try {
			if ($depth == 0) $this->begin();
			$depth++;
			call_user_func($callback); //maintain 5.2 compatibility
			$depth--;
			if ($depth == 0) $this->commit();
		} catch(Exception $e) {
			$depth--;
			if ($depth == 0) $this->rollback();
			throw $e;
		}
	}  

	/**
	 * Toggles DEBUG mode.
	 * In Debug mode all SQL that happens under the hood will
	 * be printed to the screen or logged by provided logger.
	 *
	 * @param boolean $tf
	 * @param RedBean_ILogger $logger
	 */
	public function debug($tf = true, $logger = NULL) {
		if (!$logger) $logger = new \RedBean_Logger_Default;
		if (!isset($this->adapter)) throw new \RedBean_Exception_Security('Use setup() first.');
		$this->adapter->getDatabase()->setDebugMode( $tf, $logger );
	}

	/**
	 * Stores a RedBean OODB Bean and returns the ID.
	 *
	 * @param  RedBean_OODBBean|RedBean_SimpleModel $bean bean
	 *
	 * @return integer $id id
	 */
	public function store($bean) {
		return $this->redbean->store($bean);
	}

	/**
	 * Toggles fluid or frozen mode. In fluid mode the database
	 * structure is adjusted to accomodate your objects. In frozen mode
	 * this is not the case.
	 *
	 * You can also pass an array containing a selection of frozen types.
	 * Let's call this chilly mode, it's just like fluid mode except that
	 * certain types (i.e. tables) aren't touched.
	 *
	 * @param boolean|array $trueFalse
	 */
	public function freeze($tf = true) {
		$this->redbean->freeze($tf);
	}

	/**
	* Loads multiple types of beans with the same ID.
	* This might look like a strange method, however it can be useful
	* for loading a one-to-one relation.
	* 
	* Usage:
	* list($author, $bio) = R::load('author, bio', $id);
	*
	* @param string|array $types
	* @param mixed        $id
	*
	* @return RedBean_OODBBean $bean
	*/ 
	public function loadMulti($types, $id) {
		if (is_string($types) && strpos($types, ',') !== false) $types = explode(',', $types);
		if (is_array($types)) {
			$list = array();
			foreach($types as $typeItem) {
				$list[] = $this->redbean->load($typeItem, $id);
			}
			return $list;
		}
		return array();
	}
  
	/**
	 * Loads the bean with the given type and id and returns it.
	 *
	 * Usage:
	 * $book = R::load('book', $id); -- loads a book bean
	 *
	 * Can also load one-to-one related beans:
	 * 
	 * @param string  $type type
	 * @param integer $id   id of the bean you want to load
	 *
	 * @return RedBean_OODBBean $bean
	 */
	public function load($type, $id) {
		return $this->redbean->load($type, $id);
	}

	/**
	 * Deletes the specified bean.
	 *
	 * @param RedBean_OODBBean|RedBean_SimpleModel $bean bean to be deleted
	 *
	 * @return mixed
	 */
	public function trash($bean) {
		return $this->redbean->trash($bean);
	}

	/**
	 * Dispenses a new RedBean OODB Bean for use with
	 * the rest of the methods.
	 * 
	 * @todo extract from facade
	 * 
	 *
	 * @param string  $type   type
	 * @param integer $number number of beans to dispense
	 * 
	 * @return array $oneOrMoreBeans
	 */
	public function dispense($type, $num = 1) {
		if (!preg_match('/^[a-z0-9]+$/', $type) && $this->strictType) throw new \RedBean_Exception_Security('Invalid type: '.$type); 
		return $this->redbean->dispense($type, $num);
	}

	/**
	 * Toggles strict bean type names.
	 * If set to true (default) this will forbid the use of underscores and 
	 * uppercase characters in bean type strings (R::dispense).
	 * 
	 * @param boolean $trueFalse 
	 */
	public function setStrictTyping($trueFalse) {
		$this->strictType = (boolean) $trueFalse;
	}
  
	/**
	 * Convience method. Tries to find beans of a certain type,
	 * if no beans are found, it dispenses a bean of that type.
	 *
	 * @param  string $type   type of bean you are looking for
	 * @param  string $sql    SQL code for finding the bean
	 * @param  array  $values parameters to bind to SQL
	 *
	 * @return array $beans Contains RedBean_OODBBean instances
	 */
	public function findOrDispense($type, $sql, $values) {
    return $this->finder->findOrDispense($type, $sql, $values);
	}
  
	/**
	 * Associates two Beans. This method will associate two beans with eachother.
	 * You can then get one of the beans by using the related() function and
	 * providing the other bean. You can also provide a base bean in the extra
	 * parameter. This base bean allows you to add extra information to the association
	 * record. Note that this is for advanced use only and the information will not
	 * be added to one of the beans, just to the association record.
	 * It's also possible to provide an array or JSON string as base bean. If you
	 * pass a scalar this function will interpret the base bean as having one
	 * property called 'extra' with the value of the scalar.
	 *
	 * @todo extract from facade
	 * 
	 * @param RedBean_OODBBean $bean1 bean that will be part of the association
	 * @param RedBean_OODBBean $bean2 bean that will be part of the association
	 * @param mixed $extra            bean, scalar, array or JSON providing extra data.
	 *
	 * @return mixed
	 */
	public function associate($beans1, $beans2, $extra = null) {
		if (!$extra) {
			return $this->associationManager->associate($beans1, $beans2);
		} else {
			return $this->extAssocManager->extAssociateSimple($beans1, $beans2, $extra);
		}
	}

	/**
	 * Breaks the association between two beans.
	 * This functions breaks the association between a pair of beans. After
	 * calling this functions the beans will no longer be associated with
	 * eachother. Calling related() with either one of the beans will no longer
	 * return the other bean.
	 *
	 * @param RedBean_OODBBean $bean1 bean
	 * @param RedBean_OODBBean $bean2 bean
	 *
	 * @return mixed
	 */
	public function unassociate($beans1,  $beans2, $fast = false) {
		return $this->associationManager->unassociate($beans1, $beans2, $fast);
	}

	/**
	 * Returns all the beans associated with $bean.
	 * This method will return an array containing all the beans that have
	 * been associated once with the associate() function and are still
	 * associated with the bean specified. The type parameter indicates the
	 * type of beans you are looking for. You can also pass some extra SQL and
	 * values for that SQL to filter your results after fetching the
	 * related beans.
	 *
	 * Dont try to make use of subqueries, a subquery using IN() seems to
	 * be slower than two queries!
	 *
	 * Since 3.2, you can now also pass an array of beans instead just one
	 * bean as the first parameter.
	 *
	 * @param RedBean_OODBBean|array $bean the bean you have
	 * @param string				 $type the type of beans you want
	 * @param string				 $sql  SQL snippet for extra filtering
	 * @param array					 $val  values to be inserted in SQL slots
	 *
	 * @return array $beans	beans yielded by your query.
	 */
	public function related($bean, $type, $sql = null, $values = array()) {
		return $this->associationManager->relatedSimple($bean, $type, $sql, $values);
	}

	/**
	* Returns only single associated bean.
	*
	* @param RedBean_OODBBean $bean bean provided
	* @param string $type type of bean you are searching for
	* @param string $sql SQL for extra filtering
	* @param array $values values to be inserted in SQL slots
	*
	*
	* @return RedBean_OODBBean $bean
	*/
	public function relatedOne(\RedBean_OODBBean $bean, $type, $sql = null, $values = array()) {
		return $this->associationManager->relatedOne($bean, $type, $sql, $values);
	}

	/**
	 * Checks whether a pair of beans is related N-M. This function does not
	 * check whether the beans are related in N:1 way.
	 *
	 * @param RedBean_OODBBean $bean1 first bean
	 * @param RedBean_OODBBean $bean2 second bean
	 *
	 * @return bool $yesNo whether they are related
	 */
	public function areRelated(\RedBean_OODBBean $bean1, \RedBean_OODBBean $bean2) {
		return $this->associationManager->areRelated($bean1, $bean2);
	}

	/**
	 * The opposite of related(). Returns all the beans that are not
	 * associated with the bean provided.
	 *
	 * @param RedBean_OODBBean $bean   bean provided
	 * @param string           $type   type of bean you are searching for
	 * @param string           $sql    SQL for extra filtering
	 * @param array            $values values to be inserted in SQL slots
	 *
	 * @return array $beans beans
	 */
	public function unrelated(\RedBean_OODBBean $bean, $type, $sql = null, $values = array()) {
		return $this->associationManager->unrelated($bean, $type, $sql, $values);
	}

	/**
	 * Clears all associated beans.
	 * Breaks all many-to-many associations of a bean and a specified type.
	 *
	 * @param RedBean_OODBBean $bean bean you wish to clear many-to-many relations for
	 * @param string           $type type of bean you wish to break associatons with
	 *
	 * @return void
	 */
	public function clearRelations(\RedBean_OODBBean $bean, $type) {
		$this->associationManager->clearRelations($bean, $type);
	}

	/**
	 * Finds a bean using a type and a where clause (SQL).
	 * As with most Query tools in RedBean you can provide values to
	 * be inserted in the SQL statement by populating the value
	 * array parameter; you can either use the question mark notation
	 * or the slot-notation (:keyname).
	 *
	 * @param string $type   type   the type of bean you are looking for
	 * @param string $sql    sql    SQL query to find the desired bean, starting right after WHERE clause
	 * @param array  $values values array of values to be bound to parameters in query
	 *
	 * @return array $beans  beans
	 */
	public function find($type, $sql = null, $values = array()) {
		return $this->finder->find($type, $sql, $values);
	}

	/**
	 * @see RedBean_Facade::find
	 * The findAll() method differs from the find() method in that it does
	 * not assume a WHERE-clause, so this is valid:
	 *
	 * R::findAll('person',' ORDER BY name DESC ');
	 *
	 * Your SQL does not have to start with a valid WHERE-clause condition.
	 * 
	 * @param string $type   type   the type of bean you are looking for
	 * @param string $sql    sql    SQL query to find the desired bean, starting right after WHERE clause
	 * @param array  $values values array of values to be bound to parameters in query
	 *
	 * @return array $beans  beans
	 */
	public function findAll($type, $sql = null, $values = array()) {
		return $this->finder->findAll($type, $sql, $values);
	}

	/**
	 * @see RedBean_Facade::find
	 * The variation also exports the beans (i.e. it returns arrays).
	 * 
	 * @param string $type   type   the type of bean you are looking for
	 * @param string $sql    sql    SQL query to find the desired bean, starting right after WHERE clause
	 * @param array  $values values array of values to be bound to parameters in query
	 *
	 * @return array $arrays arrays
	 */
	public function findAndExport($type, $sql = null, $values = array()) {
		return $this->finder->findAndExport($type, $sql, $values);
	}
	/**
	 * @see RedBean_Facade::find
	 * This variation returns the first bean only.
	 * 
	 * @param string $type   type   the type of bean you are looking for
	 * @param string $sql    sql    SQL query to find the desired bean, starting right after WHERE clause
	 * @param array  $values values array of values to be bound to parameters in query
	 *
	 * @return RedBean_OODBBean $bean
	 */
	public function findOne($type, $sql = null, $values = array()) {
		return $this->finder->findOne($type, $sql, $values);
	}
	/**
	 * @see RedBean_Facade::find
	 * This variation returns the last bean only.
	 * 
	 * @param string $type   type   the type of bean you are looking for
	 * @param string $sql    sql    SQL query to find the desired bean, starting right after WHERE clause
	 * @param array  $values values array of values to be bound to parameters in query
	 *
	 * @return RedBean_OODBBean $bean
	 */
	public function findLast($type, $sql = null, $values = array()) {
		return $this->finder->findLast($type, $sql, $values);
	}

	/**
	 * Returns an array of beans. Pass a type and a series of ids and
	 * this method will bring you the correspondig beans.
	 *
	 * important note: Because this method loads beans using the load()
	 * function (but faster) it will return empty beans with ID 0 for
	 * every bean that could not be located. The resulting beans will have the
	 * passed IDs as their keys.
	 *
	 * @param string $type type of beans
	 * @param array  $ids  ids to load
	 *
	 * @return array $beans resulting beans (may include empty ones)
	 */
	public function batch($type, $ids) {
		return $this->redbean->batch($type, $ids);
	}

	/**
	 * Convenience function to execute Queries directly.
	 * Executes SQL.
	 *
	 * @param string $sql	 sql    SQL query to execute
	 * @param array  $values values a list of values to be bound to query parameters
	 *
	 * @return integer $affected  number of affected rows
	 */
	public function exec($sql, $values = array()) {
		return $this->query('exec', $sql, $values);
	}

	/**
	 * Convenience function to execute Queries directly.
	 * Executes SQL.
	 *
	 * @param string $sql	 sql    SQL query to execute
	 * @param array  $values values a list of values to be bound to query parameters
	 *
	 * @return array $results
	 */
	public function getAll($sql, $values = array()) {
		return $this->query('get', $sql, $values);
	}

	/**
	 * Convenience function to execute Queries directly.
	 * Executes SQL.
	 *
	 * @param string $sql	 sql    SQL query to execute
	 * @param array  $values values a list of values to be bound to query parameters
	 *
	 * @return string $result scalar
	 */
	public function getCell($sql, $values = array()) {
		return $this->query('getCell', $sql, $values);
	}

	/**
	 * Convenience function to execute Queries directly.
	 * Executes SQL.
	 *
	 * @param string $sql	 sql    SQL query to execute
	 * @param array  $values values a list of values to be bound to query parameters
	 *
	 * @return array $results
	 */
	public function getRow($sql, $values = array()) {
		return $this->query('getRow', $sql, $values);
	}

	/**
	 * Convenience function to execute Queries directly.
	 * Executes SQL.
	 *
	 * @param string $sql	 sql    SQL query to execute
	 * @param array  $values values a list of values to be bound to query parameters
	 *
	 * @return array $results
	 */
	public function getCol($sql, $values = array()) {
		return $this->query('getCol', $sql, $values);
	}

	/**
	 * Internal Query function, executes the desired query. Used by
	 * all facade query functions. This keeps things DRY.
	 *
	 * @throws RedBean_Exception_SQL
	 *
	 * @param string $method desired query method (i.e. 'cell','col','exec' etc..)
	 * @param string $sql    the sql you want to execute
	 * @param array  $values array of values to be bound to query statement
	 *
	 * @return array $results results of query
	 */
	private function query($method, $sql, $values) {
		if (!$this->redbean->isFrozen()) {
			try {
				$rs = $this->adapter->$method($sql, $values);
			}catch(\RedBean_Exception_SQL $e) {
				if($this->writer->sqlStateIn($e->getSQLState(),
				array(
				\RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
				\RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
				)) {
					return array();
				}	else {
					throw $e;
				}
			}
			return $rs;
		}	else {
			return $this->adapter->$method($sql, $values);
		}
	}

	/**
	 * Convenience function to execute Queries directly.
	 * Executes SQL.
	 * Results will be returned as an associative array. The first
	 * column in the select clause will be used for the keys in this array and
	 * the second column will be used for the values. If only one column is
	 * selected in the query, both key and value of the array will have the
	 * value of this field for each row.
	 *
	 * @param string $sql	 sql    SQL query to execute
	 * @param array  $values values a list of values to be bound to query parameters
	 *
	 * @return array $results
	 */
	public function getAssoc($sql, $values = array()) {
		return $this->query('getAssoc', $sql, $values);
	}

	/**
	 * Makes a copy of a bean. This method makes a deep copy
	 * of the bean.The copy will have the following features.
	 * - All beans in own-lists will be duplicated as well
	 * - All references to shared beans will be copied but not the shared beans themselves
	 * - All references to parent objects (_id fields) will be copied but not the parents themselves
	 * In most cases this is the desired scenario for copying beans.
	 * This function uses a trail-array to prevent infinite recursion, if a recursive bean is found
	 * (i.e. one that already has been processed) the ID of the bean will be returned.
	 * This should not happen though.
	 *
	 * Note:
	 * This function does a reflectional database query so it may be slow.
	 *
	 * @param RedBean_OODBBean $bean  bean to be copied
	 * @param array            $trail for internal usage, pass array()
	 * @param boolean          $pid   for internal usage
	 *
	 * @return array $copiedBean the duplicated bean
	 */
	public function dup($bean, $trail = array(), $pid = false, $filters = array()) {
		$this->duplicationManager->setFilters($filters);
		return $this->duplicationManager->dup($bean, $trail, $pid);
	}

	/**
	 * Exports a collection of beans. Handy for XML/JSON exports with a
	 * Javascript framework like Dojo or ExtJS.
	 * What will be exported:
	 * - contents of the bean
	 * - all own bean lists (recursively)
	 * - all shared beans (not THEIR own lists)
	 *
	 * @param	array|RedBean_OODBBean $beans   beans to be exported
	 * @param	boolean                $parents whether you want parent beans to be exported
	 * @param   array                  $filters whitelist of types
	 *
	 * @return	array $array exported structure
	 */
	public function exportAll($beans, $parents = false, $filters = array()) {
		return $this->duplicationManager->exportAll($beans, $parents, $filters);
	}

	/**
	 * @deprecated
	 * 
	 * @param array  $beans    beans
	 * @param string $property property
	 */
	public function swap($beans, $property) {
		return $this->associationManager->swap($beans, $property);
	}

	/**
	 * Converts a series of rows to beans.
	 *
	 * @param string $type type
	 * @param array  $rows must contain an array of arrays.
	 *
	 * @return array $beans
	 */
	public function convertToBeans($type, $rows) {
		return $this->redbean->convertToBeans($type, $rows);
	}

	/**
	 * Part of RedBeanPHP Tagging API.
	 * Tests whether a bean has been associated with one ore more
	 * of the listed tags. If the third parameter is TRUE this method
	 * will return TRUE only if all tags that have been specified are indeed
	 * associated with the given bean, otherwise FALSE.
	 * If the third parameter is FALSE this
	 * method will return TRUE if one of the tags matches, FALSE if none
	 * match.
	 *
	 * @param  RedBean_OODBBean $bean bean to check for tags
	 * @param  array            $tags list of tags
	 * @param  boolean          $all  whether they must all match or just some
	 *
	 * @return boolean $didMatch whether the bean has been assoc. with the tags
	 */
	public function hasTag($bean, $tags, $all=false) {
		return $this->tagManager->hasTag($bean, $tags, $all);
	}

	/**
	 * Part of RedBeanPHP Tagging API.
	 * Removes all sepcified tags from the bean. The tags specified in
	 * the second parameter will no longer be associated with the bean.
	 *
	 * @param  RedBean_OODBBean $bean    tagged bean
	 * @param  array            $tagList list of tags (names)
	 *
	 * @return void
	 */
	public function untag($bean, $tagList) {
		return $this->tagManager->untag($bean, $tagList);
	}

	/**
	 * Part of RedBeanPHP Tagging API.
	 * Tags a bean or returns tags associated with a bean.
	 * If $tagList is null or omitted this method will return a
	 * comma separated list of tags associated with the bean provided.
	 * If $tagList is a comma separated list (string) of tags all tags will
	 * be associated with the bean.
	 * You may also pass an array instead of a string.
	 *
	 * @param RedBean_OODBBean $bean    bean
	 * @param mixed				$tagList tags
	 *
	 * @return string $commaSepListTags
	 */
	public function tag(\RedBean_OODBBean $bean, $tagList = null) {
		return $this->tagManager->tag($bean, $tagList);
	}

	/**
	 * Part of RedBeanPHP Tagging API.
	 * Adds tags to a bean.
	 * If $tagList is a comma separated list of tags all tags will
	 * be associated with the bean.
	 * You may also pass an array instead of a string.
	 *
	 * @param RedBean_OODBBean  $bean    bean
	 * @param array				$tagList list of tags to add to bean
	 *
	 * @return void
	 */
	public function addTags(\RedBean_OODBBean $bean, $tagList ) {
		return $this->tagManager->addTags($bean, $tagList);
	}

	/**
	 * Part of RedBeanPHP Tagging API.
	 * Returns all beans that have been tagged with one of the tags given.
	 *
	 * @param  $beanType type of bean you are looking for
	 * @param  $tagList  list of tags to match
	 *
	 * @return array
	 */
	public function tagged($beanType, $tagList) {
		return $this->tagManager->tagged($beanType, $tagList);
	}

	/**
	 * Part of RedBeanPHP Tagging API.
	 * Returns all beans that have been tagged with ALL of the tags given.
	 *
	 * @param  $beanType type of bean you are looking for
	 * @param  $tagList  list of tags to match
	 *
	 * @return array
	 */
	public function taggedAll($beanType, $tagList) {
		return $this->tagManager->taggedAll($beanType, $tagList);
	}

	/**
	 * Wipes all beans of type $beanType.
	 *
	 * @param string $beanType type of bean you want to destroy entirely.
	 */
	public function wipe($beanType) {
		return $this->redbean->wipe($beanType);
	}

	/**
	 * Counts beans
	 *
	 * @param string $beanType type of bean
	 * @param string $addSQL   additional SQL snippet (for filtering, limiting)
	 * @param array  $params   parameters to bind to SQL
	 *
	 * @return integer $numOfBeans
	 */
	public function count($beanType, $addSQL = '', $params = array()) {
		return $this->redbean->count($beanType, $addSQL, $params);
	}

	/**
	 * Configures the facade, want to have a new Writer? A new Object Database or a new
	 * Adapter and you want it on-the-fly? Use this method to hot-swap your facade with a new
	 * toolbox.
	 *
	 * @param RedBean_ToolBox $tb toolbox
	 *
	 * @return RedBean_ToolBox $tb old, rusty, previously used toolbox
	 */
	public function configureFacadeWithToolbox(\RedBean_ToolBox $tb) {
		$oldTools = $this->toolbox;
		$this->toolbox = $tb;
		$this->writer = $this->toolbox->getWriter();
		$this->adapter = $this->toolbox->getDatabaseAdapter();
		$this->redbean = $this->toolbox->getRedBean();
		$this->finder = new \RedBean_Finder($this->toolbox);
		$this->associationManager = new \RedBean_AssociationManager($this->toolbox);
		$this->redbean->setAssociationManager($this->associationManager);
		$this->labelMaker = new \RedBean_LabelMaker($this->toolbox);
		$this->extAssocManager = new \RedBean_AssociationManager_ExtAssociationManager($this->toolbox);
		$this->toolbox->modelHelper = new CustomModelHelper();
    $this->toolbox->modelHelper->attachEventListeners($this->redbean);
		$this->associationManager->addEventListener('delete', $this->toolbox->modelHelper );
    $helper = new CustomBeanHelper();
    $helper->setToolBox($tb); 
    $this->redbean->setBeanHelper($helper);
		$this->duplicationManager = new \RedBean_DuplicationManager($this->toolbox);
		$this->tagManager = new \RedBean_TagManager($this->toolbox);
		$this->f = new \RedBean_SQLHelper($this->adapter);
		return $oldTools;
	}

/*
	public function onEvent($event, $bean) {
		$modelHelper = $this->getModelHelper();
		$modelName = $modelHelper->getModelName($bean->getMeta('type'), $bean);
		if (!class_exists($modelName)) return null;
		$obj = $modelHelper->factory($modelName);
		$obj->loadBean($bean);
		$bean->setMeta('model', $obj);
	}
*/
  
	/**
	 * facade method for Cooker Graph.
	 *
	 * @param array   $array            array containing POST/GET fields or other data
	 * @param boolean $filterEmptyBeans whether you want to exclude empty beans
	 *
	 * @return array $arrayOfBeans Beans
	 */
  public function graph($array, $filterEmpty=false) {
    $c = new \RedBean_Plugin_Cooker();
    $c->setToolbox($this->toolbox);
    return $c->graph($array, $filterEmpty);
  }   
		
	/**
	 * Facade Convience method for adapter transaction system.
	 * Begins a transaction.
	 *
	 * @return void
	 */
	public function begin() {
		if (!$this->redbean->isFrozen()) return false;
		$this->adapter->startTransaction();
		return true;
	}

	/**
	 * Facade Convience method for adapter transaction system.
	 * Commits a transaction.
	 *
	 * @return void
	 */
	public function commit() {
		if (!$this->redbean->isFrozen()) return false;
		$this->adapter->commit();
		return true;
	}
	/**
	 * Facade Convience method for adapter transaction system.
	 * Rolls back a transaction.
	 *
	 * @return void
	 */
	public function rollback() {
		if (!$this->redbean->isFrozen()) return false;
		$this->adapter->rollback();
		return true;
	}

	/**
	 * Returns a list of columns. Format of this array:
	 * array( fieldname => type )
	 * Note that this method only works in fluid mode because it might be
	 * quite heavy on production servers!
	 *
	 * @param  string $table   name of the table (not type) you want to get columns of
	 *
	 * @return array  $columns list of columns and their types
	 */
	public function getColumns($table) {
		return $this->writer->getColumns($table);
	}

	/**
	 * Generates question mark slots for an array of values.
	 *
	 * @param array $array
	 * @return string $slots
	 */
	public function genSlots($array) {
    return $this->f->genSlots($array); 
	}

	/**
	 * Nukes the entire database.
	 *
	 * @param string  $close    close the database connection after?
	 */
	public function nuke($close = true) {
		if (!$this->redbean->isFrozen()) {
			$this->writer->wipeAll();
		}
    // This is mainly for unit tests that keep opening new connections
    if ($close) {
      $this->close(); 
    }
	}

	/**
	 * Sets a list of dependencies.
	 * A dependency list contains an entry for each dependent bean.
	 * A dependent bean will be removed if the relation with one of the
	 * dependencies gets broken.
	 *
	 * Example:
	 *
	 * array(
	 *	'page' => array('book','magazine')
	 * )
	 *
	 * A page will be removed if:
	 *
	 * unset($book->ownPage[$pageID]);
	 *
	 * or:
	 *
	 * unset($magazine->ownPage[$pageID]);
	 *
	 * but not if:
	 *
	 * unset($paper->ownPage[$pageID]);
	 *
	 *
	 * @param array $dep list of dependencies
	 */
	public function dependencies($dep) {
		$this->redbean->setDepList($dep);
  }

	/**
	 * Short hand function to store a set of beans at once, IDs will be
	 * returned as an array. For information please consult the R::store()
	 * function.
	 * A loop saver.
	 *
	 * @param array $beans list of beans to be stored
	 *
	 * @return array $ids list of resulting IDs
	 */
	public function storeAll($beans) {
		$ids = array();
		foreach($beans as $bean) $ids[] = $this->store($bean);
		return $ids;
	}

	/**
	 * Short hand function to trash a set of beans at once.
	 * For information please consult the R::trash() function.
	 * A loop saver.
	 *
	 * @param array $beans list of beans to be trashed
	 */
	public function trashAll($beans) {
		foreach($beans as $bean) $this->trash($bean);
	}

	/**
	 * A label is a bean with only an id, type and name property.
	 * This function will dispense beans for all entries in the array. The
	 * values of the array will be assigned to the name property of each
	 * individual bean.
	 *
	 * @param string $type   type of beans you would like to have
	 * @param array  $labels list of labels, names for each bean
	 *
	 * @return array $bean a list of beans with type and name property
	 */
	public function dispenseLabels($type,$labels) {
		return $this->labelMaker->dispenseLabels($type,$labels);
	}

	/**
	 * Gathers labels from beans. This function loops through the beans,
	 * collects the values of the name properties of each individual bean
	 * and stores the names in a new array. The array then gets sorted using the
	 * default sort function of PHP (sort).
	 *
	 * @param array $beans list of beans to loop
	 *
	 * @return array $array list of names of beans
	 */
	public function gatherLabels($beans) {
    return $this->labelMaker->gatherLabels($beans); 
	}


	/**
	 * Closes the database connection.
	 */
	public function close() {
		if (isset($this->adapter)){
			$this->adapter->close();
		}
	}

	/**
	 * Simple convenience function, returns ISO date formatted representation
	 * of $time.
	 *
	 * @param mixed $time UNIX timestamp
	 *
	 * @return type
	 */
	public function isoDate($time = null) {
    return \RedBean_Facade::isoDate($time);
	}

	/**
	 * Simple convenience function, returns ISO date time
	 * formatted representation
	 * of $time.
	 *
	 * @param mixed $time UNIX timestamp
	 *
	 * @return type
	 */
	public function isoDateTime($time = null) {
    return \RedBean_Facade::isoDateTime($time);
	}
	
	/**
	 * Optional accessor for neat code.
	 * Sets the database adapter you want to use.
	 * 
	 * @param RedBean_Adapter $adapter 
	 */
	public function setDatabaseAdapter(\RedBean_Adapter $adapter) {
		$this->adapter = $adapter;
	}
	/**
	 * Optional accessor for neat code.
	 * Sets the database adapter you want to use.
	 *
	 * @param RedBean_QueryWriter $writer 
	 */
	public function setWriter(\RedBean_QueryWriter $writer) {
		$this->writer = $writer;
	}
	/**
	 * Optional accessor for neat code.
	 * Sets the database adapter you want to use.
	 *
	 * @param RedBean_OODB $redbean 
	 */
	public function setRedBean(\RedBean_OODB $redbean) {
		$this->redbean = $redbean;
	}
	/**
	 * Optional accessor for neat code.
	 * Sets the database adapter you want to use.
	 *
	 * @return RedBean_DatabaseAdapter $adapter
	 */
	public function getDatabaseAdapter() {
		return $this->adapter;
	}
	/**
	 * Optional accessor for neat code.
	 * Sets the database adapter you want to use.
	 *
	 * @return RedBean_QueryWriter $writer
	 */
	public function getWriter() {
		return $this->writer;
	}
	/**
	 * Optional accessor for neat code.
	 * Sets the database adapter you want to use.
	 *
	 * @return RedBean_RedBean $redbean
	 */
	public function getRedBean() {
		return $this->redbean;
	}
  
  /**
   * Returns a reference to the model helper
  */
	public function getModelHelper() {
		return $this->toolbox->modelHelper; 
	}
  
	/**
	 * Preloads certain properties for beans.
	 * Understands aliases.
	 * 
	 * Usage: R::preload($books,array('coauthor'=>'author'));
	 * 
	 * @param array $beans beans
	 * @param array $types types to load
	 */
	public function preload($beans, $types, $closure = null) {
		return $this->redbean->preload($beans, $types, $closure);
	}
  
	//Alias for Preload.
	public function each($beans, $types, $closure = null) {
    return $this->preload($beans, $types, $closure);
  }
  
	/**
	 * Facade method for RedBean_QueryWriter_AQueryWriter::renameAssocation()
	 * 
	 * @param string|array $from
	 * @param string $to 
	 */
	public function renameAssociation($from, $to = null) { 
		\RedBean_QueryWriter_AQueryWriter::renameAssociation($from, $to); 
	}  
  
}

interface RedBean_IBeanHelper extends \RedBean_BeanHelper{};

class CustomBeanHelper implements RedBean_IBeanHelper {

  protected $toolbox;
  
	/**
	 * Sets a reference to the toolbox. 
	 *  
	 * @param RedBean_ToolBox $toolbox toolbox containing all kinds of goodies
	 */
  public function setToolbox(\RedBean_ToolBox $toolbox) {
    $this->toolbox = $toolbox; 
  }

	/**
	 * Returns a reference to the toolbox. This method returns a toolbox
	 * for beans that need to use toolbox functions. Since beans can contain
	 * lists they need a toolbox to lazy-load their relationships.
	 *  
	 * @return RedBean_ToolBox $toolbox toolbox containing all kinds of goodies
	 */
	public function getToolbox() {
    return $this->toolbox; 
	}
  
  public function getModelForBean(\RedBean_OODBBean $bean) {
		$modelName = $this->getToolbox()->modelHelper->getModelName($bean->getMeta('type'), $bean);
		if (!class_exists($modelName)) return null;
		$obj = $this->getToolbox()->modelHelper->factory($modelName);
		$obj->loadBean($bean);
		return $obj;
    
    
    
    //return $bean->getMeta('model');
  }      
}

class CustomModelHelper implements \RedBean_Observer {

	/**
	 * Holds a model formatter
	 * @var RedBean_IModelFormatter
	 */
	private $modelFormatter;
	
	
	/**
	 * Holds a dependency injector
	 * @var type 
	 */
	private $dependencyInjector;
  
  /**
	 * @var array 
	 */
	private $modelCache = array();

	/**
	 * Connects OODB to a model if a model exists for that
	 * type of bean. This connector is used in the facade.
	 *
	 * @param string $eventName
	 * @param RedBean_OODBBean $bean
	 */
	public function onEvent($eventName, $bean) {
		$bean->$eventName();
	}

	/**
	 * Given a model ID (model identifier) this method returns the
	 * full model name.
	 *
	 * @param string $model
	 * @param RedBean_OODBBean $bean
	 * 
	 * @return string $fullname
	 */
	public function getModelName($model, $bean = null) {
		if (isset($this->modelCache[$model])) return $this->modelCache[$model];
		if ($this->modelFormatter){
			$modelID = $this->modelFormatter->formatModel($model,$bean);
		}
		else {
			$modelID = 'Model_'.ucfirst($model);
		}
		$this->modelCache[$model] = $modelID;
		return $this->modelCache[$model];
	}

	/**
	 * Sets the model formatter to be used to discover a model
	 * for Fuse.
	 *
	 * @param string $modelFormatter
	 */
	public function setModelFormatter($modelFormatter) {
		$this->modelFormatter = $modelFormatter;
	}
	
	
	/**
	 * Obtains a new instance of $modelClassName, using a dependency injection
	 * container if possible.
	 * 
	 * @param string $modelClassName name of the model
	 */
	public function factory($modelClassName) {
		if ($this->dependencyInjector) {
			return $this->dependencyInjector->getInstance($modelClassName);
		}
		return new $modelClassName();
	}

	/**
	 * Sets the dependency injector to be used.
	 * 
	 * @param RedBean_DependencyInjector $di injecto to be used
	 */
	public function setDependencyInjector(\RedBean_DependencyInjector $di) {
		$this->dependencyInjector = $di;
	}
	
	/**
	 * Stops the dependency injector from resolving dependencies. Removes the
	 * reference to the dependency injector.
	 */
	public function clearDependencyInjector() {
		$this->dependencyInjector = null;
	}
  
	/**
	 * Attaches the FUSE event listeners. Now the Model Helper will listen for
	 * CRUD events. If a CRUD event occurs it will send a signal to the model
	 * that belongs to the CRUD bean and this model will take over control from
	 * there.
	 * 
	 * @param Observable $observable 
	 */
	public function attachEventListeners(\RedBean_Observable $observable) {
		foreach(array('update', 'open', 'delete', 'after_delete', 'after_update', 'dispense') as $e) $observable->addEventListener($e, $this);
	}
	
}


?>
