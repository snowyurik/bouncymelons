<?php
namespace bm;


///home/yoreck/www/vihv-subprojects/bouncymelons/BouncyMelons.php

class Exception extends \Exception {}
class EConnectionFailed extends Exception {}
class EItemNotFound extends Exception {}
class ETableOrViewNotFound extends Exception {}
class EInsertFail extends Exception {}
class EExec extends Exception {}
class ESelect extends Exception {}
class ESelectOne extends Exception {}
class EConnectionNotEstablished extends Exception {}
class EViewModelNotFound extends Exception {}
class EBmIsNull extends Exception {}

/**
 * \example basic-usage.php
 */
class BouncyMelons {
	
	const DEFAULT_ITEMS_PER_PAGE = 50;
	const TYPE_LOB = \PDO::PARAM_LOB;
	
	private $pdo;
	private $vms = [];
	private $cache;
	private $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;
	
	public function __construct() {
		$this->cache = new Cache();
	}
	
	
	/**
	 * @return \bm\Cache
	 */
	public function getCache() {
		return $this->cache;
	}


	/**
	 * manually set PDO object
	 * @param PDO $pdo
	 * 
	 * $bouncyMelons->setPDO(new PDO($dsn, $user, $password));
	 */
	public function setPDO(\PDO $pdo) {
		$this->pdo = $pdo;
	}
	
	/**
	 * you can interact with pdo directly if you want
	 * @return \PDO
	 */
	public function getPDO() {
		if(empty($this->pdo)) {
			throw new EConnectionNotEstablished("use BouncyMelons::connect() or BouncyMelons::setPDO()");
		}
		return $this->pdo;
	}
	
	/**
	 * @param String $dsn like 'mysql:dbname=bouncymelons;host=localhost'
	 * @param String $user
	 * @param String $password 
	 * @options Array $options PDO driver options, default is [PDO::ATTR_PERSISTENT => true]
	 */
	public function connect($dsn, $user, $password, $options = [\PDO::ATTR_PERSISTENT => true]) {
		try {
//            var_dump($dsn, $user, $password);
			$this->setPDO(new \PDO($dsn, $user, $password, $options));
			if(!($this->getDriver() instanceof SqliteDriver) &&
                !($this->getDriver() instanceof PgsqlDriver)
                ) {
				$this->exec('set names utf8'); // sqlite des not support set names
			}
		} catch(\PDOException $e) {
			throw new EConnectionFailed($e->getMessage());
		}
	}
	
	/**
	 * @param array $vms array of IVModel
	 */
	public function park($vms) {
		foreach($vms as $vm) {
			$this->parkOne($vm);
		}
	}
	
	/**
	 * @param IVModel $vm
	 */
	public function parkOne($vm) {
		$this->vms[] = $vm;
		$vm->setBm($this);
		$vm->onParked();
	}
	
	/**
	 * @return \bm\VModel[]
	 */
	public function getVModels() {
		return $this->vms;
	}

	/**
	 * @param String $slug
	 * @return IVModel
	 */
	public function getVmBySlug($slug) {
		foreach($this->vms as $vm) {
			$class = get_class($vm);
			if($class::getSlug()==$slug) {
				return $vm;
			}
		}
		throw new EViewModelNotFound("No view model found for slug '".$slug."'");
	}

	/**
	 * 
	 * @param string $name table name
	 * @return bool
	 */
	public function tableExist($name) {
        try {
            return false !== $this->getPDO()->query("SELECT '1' as field1 FROM ".$this->quot($name)." ");
        } catch(\PDOException $e) {
//            echo $e->getMessage();
            return false;
        }
	}
	
    
    // quote mark for names ` for mysql and sqlite " for pgsql
    public function q() {
        if($this->getDriver() instanceof PgsqlDriver) {
            return '"';
        }
        return '`';
    }
    
    public function quot($name) {
        return $this->q().$name.$this->q();
    }
    
	/**
	 * @return IDriver
	 */
	public function getDriver() {
		$att = \PDO::ATTR_DRIVER_NAME; //on some systems php treats 16 as 'non numeric' so I'll force it to be int
		$driver = @$this->getPDO()->getAttribute((int)$att);
		if($driver == 'sqlite') {
			return new SqliteDriver($this);
		}
        if($driver == 'pgsql') {
            return new PgsqlDriver($this);
        }
		return new MysqlDriver($this);
	}


    public function createTable($tableName, $fields) {
        $this->getDriver()->createTable($tableName, $fields, $this);
    }

	/**
	 * 
	 * @param string $table table name
	 * @return array like [['field'=>'fieldA','type'=>'typeOfFieldA'],['field'=>'fieldB','type'=>'typeOfFieldB'],..]
	 */
	public function getTableFields($table) {
		return $this->getDriver()->getTableFields($table);
	}

	public function getCreatePrimaryId() {
		$driver = $this->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver == 'sqlite') {
			return "`id` INTEGER PRIMARY KEY ASC"; 
		}
		return "`id` int(11) auto_increment primary key";
	}
	
	public function getCreateDefaultCharset() {
		$driver = $this->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver == 'sqlite') {
			return ""; 
		}
		return "DEFAULT CHARSET UTF8";
	}

	/**
	 * drop connection to currect database
	 */
	public function dropConnection() {
		$this->pdo = null;
	}
	
	
	/**
	 * @param string $query
	 */
	public function exec($query) {
		return $this->getDriver()->exec($query);
	}
	
	/**
	 * 
	 * @param string $sql
	 * @return array of associated array, each sub array represent a row
	 * @throws ESelect
	 */
	public function query($sql) {
		return $this->getDriver()->query($sql);
	}
	
	/**
	 * 
	 * @param string $sql
	 * @return associative array
	 * @throws EItemNotFound
	 */
	public function queryOne($sql) {
		return $this->getDriver()->queryOne($sql);
	}
	
	/**
	 * for special queries
	 * @param string $sql like 'SELECT \@\@sql_mode';
	 * @return param value
	 */
	public function queryParam($sql) {
		return $this->getDriver()->queryParam($sql);
	}

	/**
	 * 
	 * @param string $table table name
	 * @param string $where where clause
	 * @param string $limit limit
	 * @param string $orderby order by clause
	 * @return array of associated array, each sub array represent a row
	 */
	public function select($table, $fields, $where, $limit = null, $orderby = null, $groupBy = null) {
		$wr = '';
		if(!empty($where)) {
			$wr = " WHERE ".$where;
		}
		$ob = '';
		if(!empty($orderby)) {
			$ob = " ORDER BY ".$orderby;
		}
		$lim = '';
		if(!empty($limit)) {
			$lim = " LIMIT ".$limit;
		}
		$gb = '';
		if(!empty($groupBy)) {
			$gb = " GROUP BY ".$groupBy;
		}
//		return $this->getDriver()->select($table, $fields, $wr, $lim, $ob, $gb);
//		$names = [];
//		foreach($fields as $field) {
//			$names[] = $field->getName();
//		}
		return $this->query("SELECT ".$this->quot(implode($this->quot(","),$fields))." 
        FROM ".$this->quot($table)." ".$wr." ".$gb." ".$ob." ".$lim);
	}
	
	/**
	 * Similar to select, but return only first result
	 * @param string $table table name
	 * @param string $where where clause
	 * @return associated array, which represent single row
	 */
	public function selectOne($table, $fields, $where) {
		$data = $this->select($table, $fields, $where,"1");
		if(empty($data)) {
			throw new EItemNotFound();
		}
		return reset($data);
//		return $this->queryOne("SELECT * FROM `".$table."` WHERE ".$where." LIMIT 1");
	}
	
	/**
	 * @param String $table 
	 * @param Array $items associative array of values ['param1'=>'value1','param2'=>'value2']
	 * @return integer id 
	 */
	public function insert($table, $items) {
		$sqlItems = [];
		foreach($items as $key=>$value) {
			$sqlItems[] = " `".$key."` = '".$value."'";
		}
		$sql = "INSERT INTO `".$table."` SET
			".implode(",",$sqlItems)."
			";
//		var_dump($sql); exit;
		$this->getPDO()->query($sql);
		$id = $this->getPDO()->lastInsertId();
		if($id == 0) {
			throw new EInsertFail();
		}
		return $id;
	}
	
	/**
	 * @param String $table 
	 * @param Array $items associative array of values ['param1'=>'value1','param2'=>'value2']
	 * @return integer id 
	 */
	public function insertOrUpdate($table, $items) {
		return $this->getDriver()->insertOrUpdate($table, $items);
	}

	/**
	 * @param String $table
	 * @param int $id
	 */
	public function remove($table, $id) {
		$this->exec("DELETE FROM ".$this->quot($table)." WHERE id = '".(int)$id."'");
	}
	
	/**
	 * remove all data from table
	 * @param string $table
	 */
	public function truncate($table) {
		$this->getDriver()->truncate($table);
	}


	/**
	 * @param string $view view name
	 * @param string $sql select query 
	 */
	public function createViewAs($view, $sql) {
		$this->getDriver()->createViewAs($view, $sql);
	}
	
	/**
	 * @param string $name temporary table name
	 * @param string $sql select query 
	 */
	public function createTemptableAs($name, $sql) {
		$this->getDriver()->createTemptableAs($name, $sql);
	}
	
	/**
	 * set items per page for all lists globally
	 * @param int $itemsPerPage
	 */
	public function setItemsPerPage($itemsPerPage) {
		$this->itemsPerPage = $itemsPerPage;
	}
	
	/**
	 * @return int
	 */
	public function getItemsPerPage() {
		return $this->itemsPerPage;
	}


    public static function getNamespaceDelimiter() {
        return ".";
    }
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IField.php


/**
 * all fields should be compatible with this interface
 */
interface IField {
	
	/**
	 * @param $name String lowercase
	 * @param $title String
	 * @param $options Array field specific options
	 * @param $sqlType String type of column that should be used in sql table
	*/
	public function __construct($name, $title, $options=[],$sqlType=null);
	
	public function getName();

	/**
	 * change field-specific option
	 * @param $key String option name
	 * @param $value String new value
	 */
	public function setOption($key, $value);
	
	/**
	 * @param $key String option name
	 * @return option value
	 */
	public function getOption($key);
	
	
	/**
	 * change field value before storing in database, serialize, etc
	 * @param $name initial value
	 * @return modified value
	 */
	public function beforeSet($value);
	
	/**
	 * change field value, for proper display in nice way, unserialize, etc
	 */
	public function beforeRead($value);
	
	/**
	 * change field value, for editing it later
	 */
	public function beforeGet($value);
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IFilter.php


interface IFilter {
	public function createWhere($fields);
	public function createOrderBy($fields);
	public function createLimit();
	public function getItemsPerPage();
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IVModel.php


interface IVModel {
	
	/**
	 * @return \bm\IList list model
	 */
	public function getListModel();

	/**
	event invoked them vm is parked
	*/
	public function onParked();
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IGroupBy.php


interface IGroupBy {
	public function __toString();
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IList.php


interface IList {
	
	/**
	 * @return ISingle
	 */
	public function createSingle();
	
	/**
	 * get single item by id or other unique column
	 * @param $id
	 * @param $column (optional)
	 * @return ISingle
	 */
	public function getOne($id, $column='id');
	
	/**
	 * @param IFilter $filter 
	 * @return array singular items
	 */
	public function find(IFilter $filter);
	
	/**
	 * same as find, but return only first element
	 * @param IFilter $filter 
	 * @return array singular items
	 */
	public function findOne(IFilter $filter);
	
	/**
	 * @param IFilter $filter 
	 * @return int number of items that much the filter
	 */
	public function getTotal(IFilter $filter);
	
	/**
	 * @param IFilter $filter 
	 * @return int number of pages, for pagination see Filter::setItemsPerPage and Filter::setPage
	 */
	public function getMaxPages(IFilter $filter);
	
	/**
	 * same as find() but also return count of returned items and pages count
	 * 
	 * @usage list($items, $total, $pages) = $list->fullfind(new \bm\FilterPermissive(['name'=>'Sexy']), $pageNumber, $itemsPerPage));
	 * @param IFilter $filter filter instance
	 */
	public function fullfind(IFilter $filter);
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IOrderByClause.php


interface IOrderByClause {
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/ISingle.php


interface ISingle {
	
	
	public function getBm();
	public function setBm(BouncyMelons $bm);

	/**
	 * @return IField[]
	 */
	public function getFields();

	/**
	 * @param Array $data associative array of new values ['param1'=>'value1','param2'=>'value2']
	 */
	public function setData($data);
	
	/**
	 * store this object in database
	 */
	public function save();
	
	/**
	 * remove this object from database
	 */
	public function remove();


	/**
	 * @return column value
	 */
	public function get($fieldName);
	
	/**
	 * @return int id
	 */
	public function getId();
}

///home/yoreck/www/vihv-subprojects/bouncymelons/interface/IDriver.php


interface IDriver {
	
	public function __construct(BouncyMelons $bm);

	/**
	 * @param String $table table name
	 * @return array array of fields like [['field=>'fieldName','type'=>'fieldType'],[..],..]
	 */
	public function getTableFields($table);
	
	public function insertOrUpdate($table, $items);
	
	public function createViewAs($view, $sql);
	public function truncate($table);
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/group/GroupBy.php


class GroupBy implements IGroupBy {
	
	private $fieldName;
    protected $bm;

    public function setBm(BouncyMelons $bm) {
        $this->bm = $bm;
    }
    
    public function getBm() {
        return $this->bm;
    }
	
	/**
	 * @param string $fieldName
	 */
	public function __construct($fieldName) {
		$this->fieldName = $fieldName;
	}
	
	public function __toString() {
		return $this->getBm()->quot($this->fieldName);
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/single/Single.php


class EFieldsNotDeclared extends Exception {}
class EBouncyMelonsInstanceNotSet extends Exception {}
class EFieldNotFound extends Exception {}
class ERequiredFieldNotSet extends Exception {}

abstract class Single implements ISingle {
	
	const TITLE = 'title';
	
	protected $data;
    /**
     * @var array of atltered table names
     */
    protected static $alteredTables = [];

	/**
	 * @var BouncyMelons
	 */
	protected $bm;
	
	abstract public function declareFields();
	
	
	public function getVModel() {
		return $this->list->getVModel();
	}
	
	/**
	 * @return array of IField
	 */
	public function getFields() {
//		return $this->declareFields();
		$bm = $this->getBm();
		$cache = $bm->getCache();
		if($cache->cached($this->getSlug(), 'fields')) {
			return $cache->get($this->getSlug(), 'fields');
		}
		$fields = $this->declareFields();
		if(empty($fields)) {
			throw new EFieldsNotDeclared("implement \bm\Single::declareFields()");
		}
        foreach($fields as $field) {
            $field->setBm($bm);
        }
		$cache->set($this->getSlug(),'fields',$fields);
		return $fields;
	}
	
	/**
	 * @return string name of primary id field, default 'id'
	 */
	public function getIdField() {
		return 'id';
	}
	
	/**
	 * @return IField which sould be associated with title
	 */
	public function getTitleField() {
		$fields = $this->getFields();
		foreach($fields as $field) {
			if($field->getName() == self::TITLE) {
				return $field; 
			}
		}
		return reset($fields);
	}
	
	/**
	 * @param String $name
	 * @return IField
	 */
	public function getFieldByName($name) {
		foreach($this->getFields() as $field) {
			if($field->getName() == $name) {
				return $field;
			}
		}
		throw new EFieldNotFound("field '".$name."' not found in ".get_class($this));
	}

	public function setData($data) {
		foreach($data as $key=>$value) {
			$this->set($key, $value);
		}
	}
	
	public function setDataRaw($data) {
		$this->data = $data;
	}


	public function getData() {
		return $this->data;
	}
	
	public function getDataForSave() {
		$re = [];
		foreach($this->getFields() as $field) {
			if(!isset($this->data[$field->getName()])) {
				continue;
			}
			$re[$field->getName()] = $field->beforeSave($this->data[$field->getName()]);
		}
		if(!empty($this->data[$this->getIdField()])) {
			$re[$this->getIdField()] = $this->data[$this->getIdField()];
		}
		return $re;
	}
    
    public function firstSave() {
        return true;
        if(!in_array($this->getTableName(), self::$alteredTables)) {
            self::$alteredTables[] = $this->getTableName();
            return true;
        }
        return false;
    }

	public function save() {
        if($this->firstSave()) {
            // create or alter table only once to increase speed on multiple saves
            $this->createTableIfNeeded();
            $this->alterTable();
        }
        $bm = $this->getBm();
		$data = $this->getData();
		foreach($this->getFields() as $field) {
			if($field->isRequired()) {
				if(!isset($data[$field->getName()])) {
					throw new ERequiredFieldNotSet();
				}
				if($field instanceof IdField) {
					if((int)$data[$field->getName()] < 1) {
						throw new ERequiredFieldNotSet();
					}
				}
			}
		}
		$dfs = $this->getDataForSave();
		$id = $bm->insertOrUpdate($this->getTableName(),$this->getDataForSave());
		$this->setId($id);
		return $id;
	}
	
	public function remove() {
		$bm = $this->getBm();
		$bm->remove($this->getTableName(),$this->getId());
	}

	public function get($fieldName) {
		$field = $this->getFieldByName($fieldName);
		return $field->beforeGet($this->getRaw($fieldName));
	}
	
	/**
	 * @return array of all field values
	 */
	public function getAll() {
		$re = [];
		foreach($this->getFields() as $field) {
			$re[$field->getName()] = $field->beforeGet($this->getRaw($field->getName()), $this);
			if($field instanceof IdField
				&& !empty($this->getRaw($field->getName()."_id"))
				) {
				$re[$field->getName()."_id"] = $this->getRaw($field->getName()."_id");
			}	
		}
		$re['id'] = $this->getId();
		return $re;
	}
	
	public function getAllRaw() {
		$re = [];
		foreach($this->getFields() as $field) {
			$re[$field->getName()] = $this->getRaw($field->getName());
		}
		$re['id'] = $this->getId();
		return $re;
	}

	public function getRaw($fieldName) {
		return @$this->data[$fieldName];
	}
	
	public function set($fieldName, $value) {
		try{
			$field = $this->getFieldByName($fieldName);
			$this->data[$fieldName] = $field->beforeSet($value);
			return;
		} catch(EFieldNotFound $e) {}
		$this->data[$fieldName] = $value;
	}


	public function getId() {
		return $this->getRaw($this->getIdField());
	}
	
	public function setId($id) {
		$this->set($this->getIdField(), $id);
	}
	
	public function getTitle() {
		$field = $this->getTitleField();
		return $field->beforeGet($this->getRaw($field->getName()), $this);
	}

	public function createTableIfNeeded() {
        //echo "\nin createTableIfNeeded";
		$bm = $this->getBm();
		if($bm->tableExist($this->getTableName())) {
            //echo "\ntable ".$this->getTableName()." exists";
			return;
		}
        //echo "\ntable ".$this->getTableName()." not exists";
		$this->createTable();
	}
	
	public function getTableName() {
		return str_replace("\\",BouncyMelons::getNamespaceDelimiter(),strtolower(get_class($this)));
	}
	
	public function getSlug() {
		return str_replace("\\",BouncyMelons::getNamespaceDelimiter(),strtolower(get_class($this)));
	}

	public function createTable() {
		$bm = $this->getBm();
		$this->getBm()->createTable($this->getTableName(),$this->getFields());
	}
	
	public function alterTable() {
        if($this->tableStructureReadonly()) {
            return false;
        }
		$bm = $this->getBm();
		$dbFields = $bm->getTableFields($this->getTableName());
		$dbFieldNames = array_column($dbFields, 'field');
		foreach($this->getFields() as $field) {
			if($field->isCalc()) {
				continue;
			}
			if(!in_array($field->getName(), $dbFieldNames)) {
				$this->addField($field);
				continue;
			}
			foreach($dbFields as $dbField) {
				if($field->getName()==$dbField['field']){
					if($field->getSqlType() != $dbField['type']) {
						$this->updateFieldType($field);
					}
					continue;
				}
			}
		}
	}
	
	public function addField($field) {
        
        $bm = $this->getBm();
		$sql = "
			ALTER TABLE
				".$bm->quot($this->getTableName())."
			ADD
				".$bm->quot($field->getName())." ".$field->getSqlType()."
			";
		$bm->exec($sql);
	}
	
	public function updateFieldType($field) {
        $bm = $this->getBm();
        $bm->getDriver()->updateFieldType($this->getTableName(), $field->getName(), $field->getSqlType());
	}

	public function getBm() {
		if(empty($this->bm)) {
			throw new EBouncyMelonsInstanceNotSet("use \bm\Single::setBm()");
		}
		return $this->bm;
	}
	
	public function setBm(BouncyMelons $bm) {
		$this->bm = $bm;
	}
    
    public function tableStructureReadonly() {
        return false;
    }
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/single/AutoSingle.php


class AutoSingle extends Single implements ISingle {

	protected $list;
	
	public function declareFields() {
		return $this->list->declareFields();
	}

	/**
	 * @return string name of primary id field, default 'id'
	 */
	public function getIdField() {
		try {
			if(method_exists($this->getVModel(), 'getIdField')) {
				return $this->getVModel()->getIdField();
			}
		} catch (EVModelNotFound $e) {}
		return parent::getIdField();
	}
	
	public function getTitleField() {
		try {
			if(method_exists($this->getVModel(), 'getTitleField')) {
				return $this->getVModel()->getTitleField();
			}
		} catch (EVModelNotFound $e) {}
		return parent::getTitleField();
	}


	public function __construct(AutoList $list) {
		$this->list = $list;
	}
	
	public function getBm() {
		return $this->list->getBm();
	}
	
	public function getTableName() {
		return $this->list->getTableName();
	}
	
	public function getSlug() {
		return $this->list->getSlug();
	}
    
    public function tableStructureReadonly() {
        try {
			if(method_exists($this->getVModel(), 'tableStructureReadonly')) {
				return $this->getVModel()->tableStructureReadonly();
			}
		} catch (EVModelNotFound $e) {}
		return parent::tableStructureReadonly();
    }
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/list/DataList.php


class DataList {
	
	protected $vmodel;
    protected $sourceIsTable = false;
	
	public function __construct(IVModel $vmodel) {
		$this->vmodel = $vmodel;
	}
	
	public function getVModel() {
		if(empty($this->vmodel)) {
			throw new EVModelNotFound();
		}
		return $this->vmodel;
	}


	public function getTableName() {
		$single = $this->createSingle();
		return $single->getTableName();
	}
	
	public function getFields() {
		$single = $this->createSingle();
		return $single->getFields();
	}

	public function getViewName() {
		return $this->getTableName()."_view";
	}
    
    public function getSourceIsTable() {
        return $this->sourceIsTable;
    }
    
    public function setSourceIsTable($value) {
        $this->sourceIsTable = $value;
    }

	/**
	 * @param IFilter $filter 
	 * @return array singular items
	 */
	public function find(IFilter $filter) {
		$this->createViewIfNeeded();
		$bm = $this->getBm();
        //var_dump('before filter->setBm', is_null($bm));
		$filter->setBm($bm);
        //var_dump('after filter->setBm', is_null($filter->getBm()));
		$fields = $this->getFields();
		$where = $filter->createWhere($fields);
		$orderby = $filter->createOrderBy($fields);
		$limit = $filter->createLimit();
        
        $source = $this->getViewName();
        if($this->getSourceIsTable()) {
                $source = $this->getTableName();
        }
		$data = $bm->select($source//$this->getViewName()
			, $this->getFieldNames(!$this->getSourceIsTable())
//			, $this->getFields()
			, $where, $limit, $orderby
			, $filter->createGroupBy()
			);
		
		$re = [];
		foreach($data as $itemData) {
			$item = $this->createSingle();
			$item->setDataRaw($itemData);
			$re[] = $item;
		}
		return $re;
	}
	
	public function getFieldNames($includeIds = true, $includeCalc = true) {
		$re = ['id'];
		foreach($this->getFields() as $field) {
			if($field->isCalc() && !$includeCalc) {
				continue;
			}
			$re[] = $field->getName();
			if($field instanceof IdField && $includeIds) {
				$re[] = $field->getName()."_id";
			}
		}
		return $re;
	}


	/**
	 * same as find, but return only first element
	 * @param IFilter $filter 
	 * @return array singular items
	 */
	public function findOne(IFilter $filter) {
		$filter->setItemsPerPage(1);
		$items = $this->find($filter);
		if(empty($items)) {
			throw new EItemNotFound();
		}
		return reset($items);
	}


	/**
	 * @param IFilter $filter 
	 * @return int number of items that much the filter
	 */
	public function getTotal(IFilter $filter) {
		$this->createViewIfNeeded();
		$fields = $this->getFields();
		$bm = $this->getBm();
		$wr = '';
		$where = $filter->createWhere($fields);
		if(!empty($where)) {
			$wr = "WHERE ".$where;
		}
		return (int)$bm->queryParam("SELECT COUNT(*) FROM ".$bm->quot($this->getViewName())." ".$wr);
	}
	
	/**
	 * @param IFilter $filter 
	 * @return int number of pages, for pagination see Filter::setItemsPerPage and Filter::setPage
	 */
	public function getMaxPages(IFilter $filter) {
		$total = $this->getTotal($filter);
		$itemsPerPage = $filter->getItemsPerPage();
		return (int)ceil($total/$itemsPerPage);
	}

	/**
	 * same as find() but also return count of returned items and pages count
	 * 
	 * @usage list($items, $total, $pages) = $list->fullfind(new \bm\FilterPermissive(['name'=>'Sexy']), $pageNumber, $itemsPerPage));
	 * @param IFilter $filter filter instance
	 */
	public function fullfind(IFilter $filter) {
		return [
			$this->find($filter),// items
			$this->getTotal($filter),//total
			$this->getMaxPages($filter),//pages
		];
	}
	
	/**
	 * truncate table associated with this model, clear all
	 */
	public function truncate() {
		$this->createTableIfNeeded();
		$this->getBm()->truncate($this->getTableName());
	}

	public function getOne($id, $column='id') {
		$item = $this->createSingle();
		$bm = $this->getBm();
		$data = $bm->selectOne($this->getTableName(), $this->getFieldNames(false, false),$bm->quot($column)."='".$id."'");
		$item->setData($data);
		return $item;
	}
	
	public function readOne($id, $column='id') {
		$this->createViewIfNeeded();
		$item = $this->createSingle();
		$bm = $this->getBm();
		$data = $bm->selectOne($this->getViewName(), $this->getFieldNames(), $bm->quot($column)."='".$id."'");
		$item->setData($data);
		return $item;
	}
	
	public function createViewIfNeeded() {
		$bm = $this->getBm();
		$cache = $bm->getCache();
		if($cache->get($this->getSlug(), 'viewCreated')) {
			return;
		}
		try {
			$this->declareView();
		} catch(EExec $e) {
			// try to fix common issue automatically
			$this->createTableIfNeeded();
			$this->declareView();
		}
		//$this->createTableIfNeeded();///@todo how we should deal with non existing empty tables on read? what if view does not require table?
//		$this->declareView();
		$cache->set($this->getSlug(), 'viewCreated', true);
	}
	
	public function createTableIfNeeded() {
		if($this->viewOnly()) {
			return;
		}
		$single = $this->createSingle();
		$single->createTableIfNeeded();
		$single->alterTable();
	}
	
	/**
	 * override this and set to true if tou do not want to create table, but only view
	 **/
	public function viewOnly() {
		return false;
	}

	public function declareView() {
		$joins = $this->createJoins();		
		$fieldsToSelect = $this->createFieldsToSelect();
		$sqlFields = implode(',',$fieldsToSelect);
		$this->createViewAs("
			SELECT
				".$this->getBm()->quot($this->getTableName()).".id, ".$sqlFields."
			FROM
				".$this->getBm()->quot($this->getTableName())."
			".$joins);
	}
	
	public function createViewAs($sql) {
//		var_dump($sql);
		$bm = $this->getBm();
		$bm->createViewAs($this->getViewName(), $sql);
	}
	
	public function createJoins() {
		$bm = $this->getBm();
		$joins = '';
		foreach($this->getFields() as $field) {
			if($field->getName() == null) {
				continue;
			}
			if($field instanceof IdField) {
				$viewModel = $bm->getVmBySlug($field->getOption('list'));
                $list = $viewModel->getListModel(); 
				$joins .= "
					LEFT JOIN ".$bm->quot($field->getJoinTableName($list))." 
						AS ".$bm->quot($list->getTableName().$field->getName())."	
					ON (
						".$bm->quot($list->getTableName().$field->getName()).".id = ".
                        $bm->quot($this->getTableName()).".".$field->getName()."
					)";
			}
		}
		return $joins;
	}
	
	public function createFieldsToSelect($prefix = '') {
		$bm = $this->getBm();
		$fieldsToSelect = array();
		foreach($this->getFields() as $field) {
			if($field->getName()==null) {
				continue;
			}
			if($field->getOption('calc') == true) {
				continue;
			}
			$temp = $bm->quot($this->getTableName()).".".$bm->quot($field->getName());
			if(!empty($prefix)) {
				$temp .=" AS ".$bm->quot($prefix.$field->getName());
			}
			if($field instanceof IdField) {
				$viewModel = $bm->getVmBySlug($field->getOption('list'));
				$list = $viewModel->getListModel();
				$temp = $list->titleToSelect($field->getName(), $prefix);
				$temp .= ",".$bm->quot($list->getTableName().$field->getName()).".id AS ".$bm->quot($prefix.$field->getName()."_id");
			}
			$fieldsToSelect[] = $temp;
		}
		return $fieldsToSelect;
	}
	
	public function titleToSelect($fieldName, $prefix = '') {
        $bm = $this->getBm();
		$single = $this->createSingle();
		$titleField = $single->getTitleField();
		return $bm->quot($this->getTableName().$fieldName).".".$bm->quot($titleField->getName())." AS ".$bm->quot($prefix.$fieldName);
	}
	
	/**
	 * @return \bm\BouncyMelons
	 */
	public function getBm():BouncyMelons {
		return $this->vmodel->getBm();
	}
	
//	public function getTableName() {
//		return $this->vmodel->getTableName();
//	}
	
	public function getSlug() {
		return $this->vmodel->getSlug();
	}
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/list/AutoList.php


class AutoList extends DataList implements IList {
	
//	protected $vmodel;
//	
//	public function __construct(IVModel $vmodel) {
//		$this->vmodel = $vmodel;
//	}

	public function createSingle() {
		if(method_exists($this->vmodel, 'createSingle')) {
			return $this->vmodel->createSingle();
		}
		return new AutoSingle($this);
	}
	
	public function declareFields() {
		return $this->vmodel->declareFields();
	}
	
	public function getIdField() {
		if(method_exists($this->vmodel, 'getIdField')) {
			return $this->vmodel->getIdField();
		}
		parent::viewOnly();
	}
	
	public function viewOnly() {
		if(method_exists($this->vmodel, 'viewOnly')) {
			return $this->vmodel->viewOnly();
		}
		parent::viewOnly();
	}


	
	public function declareView() {
		if(method_exists($this->vmodel, 'declareView')) {
			return $this->vmodel->declareView();
		}
		return parent::declareView();
	}


//	public function getBm() {
//		return $this->vmodel->getBm();
//	}
	
	public function getTableName() {
		return $this->vmodel->getTableName();
	}
	
	public function getSlug() {
		return $this->vmodel->getSlug();
	}
	
	public function parentCreateFieldsToSelect($prefix='') {
		return parent::createFieldsToSelect($prefix);
	}


	public function createFieldsToSelect($prefix = '') {
		if(method_exists($this->vmodel, 'createFieldsToSelect')) {
			return $this->vmodel->createFieldsToSelect($prefix);
		}
		return $this->parentCreateFieldsToSelect($prefix);
	}
	
	public function parentTitleToSelect($fieldName, $prefix = '') {
		return parent::titleToSelect($fieldName, $prefix);
	}


	public function titleToSelect($fieldName, $prefix = '') {
		if(method_exists($this->vmodel, 'titleToSelect')) {
			return $this->vmodel->titleToSelect($fieldName, $prefix);
		}
		return $this->parentTitleToSelect($fieldName, $prefix);
	}
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/Field.php


class ERequiredFieldOptionNotSet extends Exception {}
class ERequiredFieldOptionWrong extends Exception {}

class Field implements IField{
	
	protected $name;
	protected $title;
	protected $sqlType;
	protected $options = [];
    protected $bm;
	
	/**
	 * @param string $name field name, should be latin lowercase, no spaces 
	 * @param string $title human readable name, optional
	 * @param type $options field specific options, may vary for differect fied types, optional
	 * @param type $sqlType sql type which will be used to store field data, optional
	 * 
	 * possible options:
	 * bool required,
	 * any default
	 */
	public function __construct($name, $title=null, $options = array(), $sqlType = null) {
		$this->name = $name;
		$this->title = $title;
		$this->options = $options;
		$this->sqlType = $sqlType;
	}
    
    public function setBm(BouncyMelons $bm) {
        $this->bm = $bm;
    }
    
    public function getBm() {
        return $this->bm;
    }
	
	/**
	 * @return string field name
	 */
	public function getName() {
		return $this->name;
	}
	
	/**
	 * @return string human readable title
	 */
	public function getTitle() {
		if(empty($this->title)) {
			return $this->getName();
		}
		return $this->title;
	}

	/**
	 * for serialization, to be used in filter forms
	 * @return string
	 */
	public function getType() {
		$exploded = explode("\\",get_class($this));
		return strtolower(str_replace("Field","",end($exploded)));
	}

	/**
	 * @param string $key
	 * @return any field option by name
	 */
	public function getOption($key) {
		return @$this->options[$key];
	}
	
	public function isRequired() {
		return $this->getOption('required') == true;
	}
	
	public function isCalc() {
		return $this->getOption('calc') == true;
	}

	/**
	 * @return get sql type, lowercased
	 */
	public function getSqlType() {
		if(empty($this->sqlType)) {
			return $this->getBm()->getDriver()->correctType(static::DEFAULT_SQL_TYPE);
		}
		return strtolower($this->sqlType);
	}

	/**
	 * invoked in Single::get(), hook for data modification
	 * 
	 * @param string $value
	 * @param \bm\ISingle $single
	 * @return string or any other type
	 */
	public function beforeGet($value) {
//		var_dump($this->getName(),$value);
		if(!empty($this->getOption('default')) 
			&& empty($value)
			&& $value !== false
			&& $value !== 0
			&& $value !== '0'
			) {
			$value = $this->getOption('default');
			}
		return $value;
	}

	/**
	 * invoked in Single::read(), hook for data modification
	 * 
	 * @param string $value
	 * @return string or any other type
	 */
	public function beforeRead($value) {
		
	}

	/**
	 * invoked in Single::set() which itselt used by Single::setData(),  hook for data modification
	 * @param type $value
	 * @return type
	 */
	public function beforeSet($value) {
		return $value;
	}
	
	/**
	 * 
	 */
	public function beforeSave($value) {
		return $value;
	}

	/**
	 * modify field-specific option
	 * 
	 * @param string $key
	 * @param any $value
	 */
	public function setOption($key, $value) {
		$this->options[$key] = $value;
	}

}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/PhoneField.php

class PhoneField extends Field {
	const DEFAULT_SQL_TYPE = "varchar(11)";
	
	public function beforeSet($value) {
		return preg_replace('/[^0-9]/', '', $value);
	}


	public function beforeGet($value) {
		$val = parent::beforeGet($value);
		return $this->formatPhone($val);
	}
	
	public function formatPhone($value) {
		$mask = "+*(***)***-**-**";
		if(!empty($this->getOption('mask'))) {
			$mask = $this->getOption('mask');
		}
		$dmask = trim(preg_replace('/[^\*]/',',',$mask),",");
//		$uexploded = explode("*",$mask);
//		$delimiters = [];
//		foreach($uexploded as $item) {
//			if(empty($item)) {
//				continue;
//			}
//			$delimiters[] = $item;
//		}
//		var_dump($delimiters);
		$dexploded = explode(",",$dmask);
//		var_dump($dexploded);
		$inputPattern = "";
//		$varcount = 0;
		foreach($dexploded as $item) {
			if(empty($item)) {
				continue;
			}
			$inputPattern .="([0-9]{".strlen($item)."})";
//			$varcount++;
		}
//		var_dump($inputPattern);
		$outputPattern = "";
		$prevL = '';
		$counter = 1;
		foreach(str_split($mask) as $l) {
			if($l != '*') {
				$outputPattern .= $l;
				$prevL = $l;
				continue;
			}
			if($l != $prevL) {
				$outputPattern .= "$".$counter;
				$counter++;
			}
			$prevL = $l;
		}
//		var_dump($outputPattern);
		return preg_replace('/'.$inputPattern.'/', $outputPattern, $value);
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/IntField.php


class IntField extends Field {
	
	const DEFAULT_SQL_TYPE = "int(11)";
	
    public function beforeSet($value) {
        return (int)$value;
    }
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/BoolField.php


/**
 * handy for \<input type="checkbox"/>
 */
class BoolField extends Field {
	
	const DEFAULT_SQL_TYPE = "int(1)";
	
	public function beforeSet($value) {
//		var_dump('AAAAAAAAAAAAAAA'); // bug should not be called on read
		if($value == 'on') {
			$value = 1;
		}
		if(empty($value)) {
			$value = 0;
		}
		return parent::beforeSet($value);
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/SetField.php



/**
 * 'set' is required paramater for this field,
 * example:
 * new \\bm\\SetField('melons',null,['set'=>[
				self::TITS => 'Titties!',
				self::BOOBS => 'Boobies!',
			]])
 */
class SetField extends Field {
	
	const DEFAULT_SQL_TYPE = "tinyint";
	
	public function __construct($name, $title = null, $options = array(), $sqlType = null) {
		if(empty($options['set'])) {
			throw new ERequiredFieldOptionNotSet("\bm\SetField fields option 'set' is empty, check docs for more info");
		}
		if(!is_array($options['set'])) {
			throw new ERequiredFieldOptionWrong("\bm\SetField field option 'set' should be one-dimentional assosiative array, check docs for more info" );
		}
		parent::__construct($name, $title, $options, $sqlType);
	}


	public function beforeGet($value) {
		$val = parent::beforeGet($value);
		$set = $this->getOption('set');
		if(!empty($set[$val])){
			return $set[$val];
		}
		return $val;
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/StringField.php


class StringField extends Field {
	
	const DEFAULT_SQL_TYPE = "varchar(255)";
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/PasswordField.php


class PasswordField extends StringField {
	const DEFAULT_SQL_TYPE = "varchar(255)";
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/IdField.php


/**
 * for atomatic join another tables or views by id
 * useful options
 *	 list - slug of linked view model, required
 *   jointype - type of join, optional, can be \bm\IdField::JOIN_VIEW or \bm\IdField::JOIN_TABLE (default)
 * example
 *	new \bm\IdField($name, $title, ['list'=>VMyLinkedModel::getSlug(),'jointype'=>\bm\IdFiedl::JOIN_VIEW]);
 */
class IdField extends Field {
	const DEFAULT_SQL_TYPE = "int(11)";
	const JOIN_VIEW = 1;
	const JOIN_TABLE = 0;
	
	public function getJoinTableName($list) {
		if(!empty($this->getOption('jointype'))) {
			if($this->getOption('jointype')== self::JOIN_VIEW) {
				return $list->getViewName();
			}
		}
		return $list->getTableName();
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/TextField.php


class TextField extends Field {
	const DEFAULT_SQL_TYPE = "text";
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/DateTimeField.php


class DateTimeField extends Field{
	const DEFAULT_SQL_TYPE = "datetime";
	
	public function beforeSet($value) {
		return date('Y-m-d H:i:s',strtotime($value));
	}
	
	public function beforeGet($value) {
		$val = parent::beforeGet($value);
		$format = $this->getOption('format');
		if(empty($format)) {
			$format = "Y-m-d H:i:s";
		}
        if(strpos($format,'%') !== false) {
            return strftime($format,strtotime($value));
        }
		return date($format,strtotime($value));
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/BlobField.php


/**
 * set as filename
 * get as resource handle (for fpassthru or whatever)
 */
class BlobField extends Field {
	
	const DEFAULT_SQL_TYPE = 'MEDIUMBLOB';
	
	/**
	 * @params string $value filename to get content from
	 */
	public function beforeSet($value) {
		return $value;
	}
	
	public function beforeGet($value) {
		return $value;
	}
	
	public function beforeSave($value) {
		return fopen($value,'rb');
	}
	
	public function beforeLoad($value) {
		return $value;
	}
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/TimeField.php


class TimeField extends Field {
	const DEFAULT_SQL_TYPE = "time";
	
	public function beforeSet($value) {
		return date('H:i:s',strtotime($value));
	}
	
	public function beforeGet($value) {
		$val = parent::beforeGet($value);
		$format = $this->getOption('format');
		if(empty($format)) {
			$format = "H:i:s";
		}
		return date($format,strtotime($value));
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/field/DateField.php


/**
 * example
 * <pre><code>
 * new DateField('mydate', // required
 *		'My Human Readable Date', // optional
 *		[
 *			'format'=>'Y-m-d',// date format to represent as a string, optional
 *			'default'=>'2000-01-01', // default date value, optional
 *		]);
 * </code></pre>
 */
class DateField extends Field {
	const DEFAULT_SQL_TYPE = "date";
	
	public function beforeSet($value) {
		return date('Y-m-d',strtotime($value));
	}
	
	public function beforeGet($value) {
		$val = parent::beforeGet($value);
		$format = $this->getOption('format');
		if(empty($format)) {
			$format = "Y-m-d";
		}
		return date($format,strtotime($value));
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/misc/Cache.php


/**
 * in memory cashe
 */
class Cache {

	private $items = [];
	
	public function notcached($class, $key) {
		$className = $class;
		if(is_object($class)) {
			$className = get_class($class);
		}
		if(empty($this->items[$className])) {
			return true;
		}
		if(!isset($this->items[$className][$key])) {
			return true;
		}
		return false;
	}
	
	public function cached($class, $key) {
		return !$this->notcached($class, $key);
	}


	public function get($class, $key) {
		$className = $class;
		if(is_object($class)) {
			$className = get_class($class);
		}
//		var_dump([get_class($class), $key, $this->items]);
		return @$this->items[$className][$key];
	}
	
	public function set($class, $key,$value){
		$className = $class;
		if(is_object($class)) {
			$className = get_class($class);
		}
		$this->items[$className][$key] = $value;
	}
	
}


///home/yoreck/www/vihv-subprojects/bouncymelons/inc/viewmodel/VModel.php


class VModel implements IVModel {
	
	protected $bm;
	protected $list;


	public function onParked() {}
	
	/**
	 * override this to use custom list model
	 * @return \bm\AutoList
	 */
	public function createList() {
		return new AutoList($this);
	}

	/**
	 * @return \bm\IList
	 */
	public function getListModel() {
		if(empty($this->list)) {
			$this->list = $this->createList();
		}
		return $this->list;
	}
	
	public function setBm(BouncyMelons $bm) {
		$this->bm = $bm;
	}
	
	public function getBm() {
		return $this->bm;
	}
	
	public function getFields() {
		return $this->getListModel()->getFields();
	}
	
	public function getTableName() {
		$list = $this->getListModel();
		if($list instanceof AutoList) {
			return $this->readSlug();
		}
		return $list->getTableName();
	}
	
	public function getViewName() {
		return $this->getListModel()->getViewName();
	}

	public static function getSlug() {
		return str_replace("\\",BouncyMelons::getNamespaceDelimiter(),strtolower(static::class));
	}
	
	public function getTitle() {
		return $this->readSlug();
	}


	/**
	 * non-static alias for getSlug, should not be overriden
	 */
	public function readSlug() {
		return static::getSlug();
	}
	
	public function createViewAs($sql) {
		return $this->getListModel()->createViewAs($sql);
	} 
	
	public function createTemptableAs($sql) {
		return $this->getListModel()->createTemptableAs($sql);
	}
	
	
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/sort/OrderByClause.php


abstract class OrderByClause implements IOrderByClause {
	
	/**
	 * @var string
	 */
	protected $fieldName;
    protected $bm;

    public function setBm(BouncyMelons $bm) {
        $this->bm = $bm;
    }
    
    public function getBm() {
        return $this->bm;
    }
	public function __construct($fieldName) {
		$this->fieldName = $fieldName;
	}
	
	public function getFieldName() {
		return $this->fieldName;
	}

	abstract public function get();
	
	public function __toString() {
		return $this->get();
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/sort/Asc.php


class Asc extends OrderByClause {	
	public function get() {
		return $this->getBm()->quot($this->getFieldName())." ASC"; 
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/sort/Desc.php


class Desc extends OrderByClause {
	
	public function get() {
		return $this->getBm()->quot($this->getFieldName())." DESC"; 
	}

}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/filter/Filter.php


abstract class Filter implements IFilter {
	
	const DEFAULT_PAGE = 0;
	const DEFAULT_ITEMS_PER_PAGE = 50;
	
	/**
	 * this is to indicate, that no LIMIT should be in sql query
	 * example:
	 * $list->find(\bm\FilterAll(null, \bm\Filter::INFINITY));
	 */
	const INFINITY = -1;
	
	protected $page;
	protected $itemsPerPage;
	protected $orderBy;
	protected $groupBy;
    
    //@var BouncyMelons
	private $bm;


	public function __constcruct() {}
	
	public function setBm(BouncyMelons $bm) {
        if($bm == null) {
//            var_dump($bm);
            throw new EBmIsNull();
        }
		$this->bm = $bm;
//        var_dump("Filter setBm");
        //var_dump("bm null ",is_null($this->bm));
	}
	
	public function getBm():BouncyMelons {
        /*if(empty($this->bm)) {
            throw new EBmIsNull();
        }*/
		return $this->bm;
	}

	public function setPage($page = null) {
		$this->page = $page;
	}
	
	public function getPage() {
		if($this->page !== null) {
			return $this->page;
		}
		return self::DEFAULT_PAGE;
	}

	public function setItemsPerPage($itemsPerPage = null) {
		$this->itemsPerPage = $itemsPerPage;
	}
	
	public function getItemsPerPage() {
		if($this->itemsPerPage !== null) {
			return $this->itemsPerPage;
		}
		return $this->getDefaultItemsPerPage();
	}
	
	/**
	 * @param array of IOrderByClause $orderBy
	 */
	public function setOrderBy($orderBy) {
		$this->orderBy = $orderBy;
//        foreach($this->orderBy as $item) { 
//            $item->setBm($this->getBm());
//        }
	}
	
	public function setGroupBy($groupBy) {
		$this->groupBy = $groupBy;
//        foreach($this->groupBy as $item) {
//            $item->setBm($this->getBm());
//        }
	}

	/**
	 * @return int 
	 */
	public function getDefaultItemsPerPage() {
		return $this->getBm()->getItemsPerPage();
	}
	
	public function createLimit() {
		$itemsperpage = $this->getItemsPerPage();
		if($itemsperpage == self::INFINITY) {
			return "";
		}
		$offset = $this->getPage()*$itemsperpage;
		return $itemsperpage.' OFFSET '.$offset;
	}

	public function createOrderBy($fields) {
        foreach($this->orderBy as $item) { 
            $item->setBm($this->getBm());
        }
		return implode(",",$this->orderBy);
	}
	
	public function createGroupBy() {
        foreach($this->groupBy as $item) {
            $item->setBm($this->getBm());
        }
		return implode(",",$this->groupBy);
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/filter/FilterAll.php


class FilterAll extends Filter {
	
	/**
	 * 
	 * @param int $page
	 * @param int $itemsPerPage
	 * @param array of IOrderByClause $orderBy
	 */
	public function __construct($page = null, $itemsPerPage = null, $orderBy = [], $groupBy = []) {
		$this->setPage($page);
		$this->setItemsPerPage($itemsPerPage);
		$this->setOrderBy($orderBy);
		$this->setGroupBy($groupBy);
	}

	public function createWhere($fields) {
		return '';
	}

}



///home/yoreck/www/vihv-subprojects/bouncymelons/inc/filter/FilterComplex.php


class FilterComplex extends FilterAll {
	
	/**
	 * @var array
	 */
	protected $where;
	
	/**
	 * @param array $where where clause
	 * @param int $page
	 * @param int $itemsPerPage
	 * @param array of IOrderByClause $orderBy
	 * @param array of IGroupBy $groupBy
	 */
	public function __construct($where, $page = null, $itemsPerPage = null, $orderBy = [], $groupBy  = []) {
		parent::__construct($page, $itemsPerPage, $orderBy, $groupBy);
		$this->setWhere($where);
	}
	
	/**
	 * @param array $where
	 */
	public function setWhere($where) {
		$this->where = $where;
	}
	
	/**
	 * @return array
	 */
	public function getWhere() {
		return $this->where;
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/filter/FilterRaw.php


class FilterRaw extends FilterAll {

	/**
	 * @var string
	 */
	protected $where;
	
	/**
	 * @param string $where where clause
	 * @param int $page
	 * @param int $itemsPerPage
	 * @param array of IOrderByClause $orderBy
	 */
	public function __construct($where, $page = null, $itemsPerPage = null, $orderBy = []) {
		parent::__construct($page, $itemsPerPage, $orderBy);
		$this->setWhere($where);
	}
	
	/**
	 * @param array of IField $fields
	 * @return string
	 */
	public function createWhere($fields) {
		return $this->getWhere();
	}
	
	/**
	 * @param string $where
	 */
	public function setWhere($where) {
		$this->where = $where;
	}
	
	/**
	 * @return string
	 */
	public function getWhere() {
		return $this->where;
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/filter/FilterPermissive.php

class FilterPermissive extends FilterComplex {
	
	/**
	 * @param array of IField $fields
	 * @return string
	 */
	public function createWhere($fields) {
        $bm = $this->getBm();
		$input = $this->getWhere();
		$re = [];
		foreach ($fields as $field) {
			if($field instanceof IdField) {
				if(!empty($input[$field->getName()."_id"])) {
					$re[] = $bm->quot($field->getName()."_id")." = '".$input[$field->getName()."_id"]."'";
					continue;
				}
			}
			if($field instanceof BoolField
                || $field instanceof IntField
            ) {
				if(isset($input[$field->getName()])) {
					$re[] = $bm->quot($field->getName())."='".$input[$field->getName()]."'";
				}
				continue;
			}
			if (empty($input[$field->getName()])) {
				continue;
			}
			if(is_array($input[$field->getName()])) {
				if(!empty($input[$field->getName()]['from'])
					&& !empty($input[$field->getName()]['to'])) {
					$re[] = $bm->quot($field->getName())." BETWEEN 
						'".$field->beforeSet($input[$field->getName()]['from'])."'
						AND '".$field->beforeSet($input[$field->getName()]['to'])."'";
					continue;
					}
				if(!empty($input[$field->getName()]['from'])) {
					$re[] = $bm->quot($field->getName())." = '".$input[$field->getName()]['from']."'";
					continue;
				}
				$re[] = $bm->quot($field->getName())." IN ('".implode("','",$input[$field->getName()])."')";
				continue;
			}
            
			$re[] = $bm->quot($field->getName())." LIKE '%".$input[$field->getName()]."%'";
		}
//		var_dump(implode(' AND ', $re));
		return implode(' AND ', $re);
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/filter/FilterStrict.php

class FilterStrict extends FilterComplex {
	
	/**
	 * @param array of IField $fields
	 * @return string
	 */
	public function createWhere($fields) {
        $bm = $this->getBm();
		$input = $this->getWhere();
//		var_dump($input,  array_key_exists('lkey', $input));
		$re = [];
		$fields[] = new \bm\IntField('id');
		foreach ($fields as $field) {
//			var_dump($field->getName());
			if($field instanceof \bm\IdField) {
				if(array_key_exists($field->getName()."_id", $input)) {
					$re[] = $bm->quot($field->getName()."_id")." = '".$input[$field->getName()."_id"]."'";
				}
			}
			if (!array_key_exists($field->getName(), $input)) {
				continue;
			}
			if(is_array($input[$field->getName()])) {
				$re[] = $bm->quot($field->getName())." IN ('".implode("','",$input[$field->getName()])."')";
				continue;
			}
			$re[] = $bm->quot($field->getName())." = '".$input[$field->getName()]."'";
		}
//		var_dump(implode(' AND ', $re));
		return implode(' AND ', $re);
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/drivers/Driver.php


class Driver {
	
    //@var \PDO
	protected $pdo;
    
    //@var BouncyMelons
    protected $bm;
	
	public function __construct(BouncyMelons $bm) {
		$this->pdo = $bm->getPDO();
        $this->bm = $bm;
	}
	
	public function getPDO() {
		return $this->pdo;
	}
    
    public function getBm() {
        return $this->bm;
    }
	
	public function exec($query) {
//		var_dump($query); ///@todo debug mode, log queries to some object like $bm->log($query); $bm->getLog();
		$statement = $this->getPDO()->query($query);
		if(false === $statement) {
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
//			var_dump($query);
			throw new EExec($msg, $errint);
		}
		$statement = null;
	}
    
    public function quot(String $value) {
        return $this->getBm()->quot($value);
    }
	
	/**
	 * 
	 * @param string $table
	 * @param \bm\IField[] $fields array of fields
	 */
	public function select($table, $fields, $where='', $limit='', $orderby='', $groupBy='') {
//		var_dump('in Driver::select');
		$names = [];
		foreach($fields as $field) {
			$names[] = $this->quot($field->getName());
		}
		$pdo = $this->getPDO();
		$sql = "SELECT ".implode(",",$names)." FROM ".$this->quot($table)." ".$where." ".$limit." ".$orderby." ".$groupBy;
//		var_dump($sql);
		$statement = $pdo->prepare($sql);
//		$pdo->beginTransaction();
		$statement->execute();
//		$pdo->commit();
		$counter = 0;
		$re = [];
		foreach($fields as $field) {
//			$re[$field->getName()] = null;
			$counter++;
			if($field instanceof BlobField) {
//				var_dump($field->getName()." is blob field");
				$statement->bindColumn($counter,$re[$field->getName()],\PDO::PARAM_LOB);
				continue;
			}
			$statement->bindColumn($counter,$re[$field->getName()]);
		}
//		$statement->execute();
		$statement->fetch(\PDO::FETCH_BOUND);
//		$statement->execute();
//		var_dump($re);
		return $re;
	}
	
	/**
	 * 
	 * @param string $sql
	 * @return array of associated array, each sub array represent a row
	 * @throws ESelect
	 */
	public function query($sql) {
//		$pdo = $this->getPDO();
//		$statement = $pdo->prepare($sql);
		
		
//		var_dump($sql);
		$re = $this->getPDO()->query($sql);
		if($re === false) {
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
			throw new ESelect($msg, $errint);
		}
		$data = $re->fetchAll(\PDO::FETCH_ASSOC);
		$re = null;
		return $data;
	}
	
	/**
	 * 
	 * @param string $sql
	 * @return associative array
	 * @throws EItemNotFound
	 */
	public function queryOne($sql) {
		$data = $this->query($sql);
		if(empty($data)) {
			throw new EItemNotFound();
		}
		return reset($data);
	}
	
	/**
	 * for special queries
	 * @param string $sql like 'SELECT \@\@sql_mode';
	 * @return param value
	 */
	public function queryParam($sql) {
		$data = $this->queryOne($sql);
		return reset($data);
	}
    
    public function createTable($tableName, $fields, $bm) {
        $sqlFields = [];
		foreach($fields as $field) {
			if($field->isCalc()) {
				continue;
			}
			$sqlFields[] ="`".$field->getName()."` ".$field->getSqlType();
		}
        $sql = "CREATE TABLE `".$tableName."`(
				".$bm->getCreatePrimaryId().",
				".implode(",",$sqlFields)."
				) ".$bm->getCreateDefaultCharset();
//		var_dump("create table sql:", $sql);
		$this->exec($sql);
    }
    
    public function correctType($type) {
        return $type;
    }
    
    public function updateFieldType($table, $field, $type) {
        $sql = "
			ALTER TABLE
				".$this->getBm()->quot($table)."
			MODIFY COLUMN
				".$this->getBm()->quot($field)." ".$type."
			";
		$this->exec($sql);
    }
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/drivers/MysqlDriver.php


class MysqlDriver extends Driver implements IDriver {

	public function getTableFields($table) {
		$list = $this->pdo->query("SHOW FIELDS FROM `".$table."`")->fetchAll(\PDO::FETCH_ASSOC);
		$re = [];
		foreach($list as $item) {
			$re[] = [
				'field' => $item['Field'],
				'type' => $item['Type'],
			];
		}
		return $re;
	}

	public function insertOrUpdate($table, $items) {
		$sqlItems = [];
		foreach($items as $key=>$value) {
			$sqlItems[] = " `".$key."` = ?";
//			$sqlItems[] = " `".$key."` = '".$value."'";
		}
		$sql = "INSERT INTO `".$table."` SET
			".implode(",",$sqlItems)."
			ON DUPLICATE KEY UPDATE ".implode(",",$sqlItems)." ";
//		var_dump($sql);
		$pdo = $this->getPDO();
		$statement = $pdo->prepare($sql);
		$counter = 0;
		for($i=0; $i<2;$i++){//repeat twice, for insert and for onduplicate key update
			foreach($items as $key=>$value) {
//				var_dump($value);
				$counter++;
				if(is_resource($value)) {
//					var_dump($value);
					$statement->bindParam($counter, $items[$key], \PDO::PARAM_LOB);
					continue;
				}
				$statement->bindParam($counter, $items[$key]);
				
			}
		}
//		$re = $this->getPDO()->query($sql);
//		var_dump($sql, $counter);
		$pdo->beginTransaction();
		$statement->execute();
		$id = $this->getPDO()->lastInsertId();
		$re = $pdo->commit();
		
		if($re === false) {
//			var_dump($this->getPDO()->errorInfo()); exit;
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
			throw new EInsertFail($msg, $errint);
		}
		if(!empty($items['id'])) {
			return $items['id'];
		}
//		$id = $this->getPDO()->lastInsertId();
		if($id == 0) {
//			var_dump('Cus id is zero');
			throw new EInsertFail();
		}
		return $id;
	}
	
	public function createViewAs($view, $sql) {
		$this->exec("CREATE OR REPLACE SQL SECURITY INVOKER VIEW `".$view."` AS ".$sql);
	}

	public function createTemptableAs($name, $sql) {
		$this->exec("CREATE TEMPORARY TABLE `".$name."` AS ".$sql);
	}
	
	public function truncate($table) {
		$this->exec("TRUNCATE ".$this->getBm()->quot($table));
	}
}

///home/yoreck/www/vihv-subprojects/bouncymelons/inc/drivers/PgsqlDriver.php

class PgsqlDriver extends MysqlDriver implements IDriver {
    
    public function insertOrUpdate($table, $items) {
		$columns = array_keys($items);
		$values = array_values($items);
		$sql = "INSERT INTO \"".$table."\"
				(\"".implode("\",\"",$columns)."\") 
				VALUES ('".implode("','",$values)."')";
        if(!empty($items['id'])) {
            $sql = 'UPDATE "'.$table.'"
                SET ';
            $first = true;
            foreach($items as $key=>$value) {
                if($key == 'id') {
                    continue;
                }
                if(!$first) {
                    $sql .=" , ";
                }
                if($first) { 
                    $first = false;
                }
                $sql .= "\"".$key."\" = '".$value."'";
            }
            $sql .= " WHERE id='".$items['id']."'";
        }
//        var_dump($sql);
		$re = $this->pdo->query($sql);
		if($re === false) {
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
			throw new EInsertFail($sql.$msg, $errint);
		}
        if(!empty($items['id'])) {
            return $items['id'];
        }
		$id = $this->pdo->lastInsertId();
		if($id == 0) {
			throw new EInsertFail($sql);
		}
		return $id;
	}
    
    public function getTableFields($table) {
		$sql = "SELECT
            a.attname as \"Field\",
            pg_catalog.format_type(a.atttypid, a.atttypmod) as \"Type\"
        FROM
            pg_catalog.pg_attribute a
        WHERE
            a.attnum > 0
            AND NOT a.attisdropped
            AND a.attrelid = (
                SELECT c.oid
                FROM pg_catalog.pg_class c
                WHERE c.relname = '".$table."'
                    AND pg_catalog.pg_table_is_visible(c.oid)
        )";
        $sql = str_replace("\n"," ", $sql);
//        var_dump($sql);
        $list = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
		$re = [];
		foreach($list as $item) {
			$re[] = [
				'field' => $item['Field'],
				'type' => strtolower($item['Type']),
			];
		}
//        var_dump($re);
		return $re;
	}
    
    public function createTable($tableName, $fields, $bm) {
        $sqlFields = [];
		foreach($fields as $field) {
			if($field->isCalc()) {
				continue;
			}
			$sqlFields[] ="".$this->quot($field->getName())." ".$field->getSqlType();
		}
        $sql = "CREATE TABLE ".$this->quot($tableName)."(
				id SERIAL PRIMARY KEY,
				".implode(",",$sqlFields)."
				) ";//.$bm->getCreateDefaultCharset();
//		var_dump("create table sql:", $sql);
		$this->exec($sql);
    }
    
    public function correctType($type) {
        if($type == 'int(11)') {
            return 'integer';
        }
        if($type == 'int(1)') {
            return 'smallint';
        }
        if($type == 'varchar(255)') {
            return 'character varying(255)';
        }
        if($type == 'datetime') {
            return 'timestamp without time zone';
        }
        if($type == 'tinyint') {
            return 'integer';
        }
        if($type == 'mediumblob') {
            return 'bytea';
        }
        return $type;
    }
    
    public function createViewAs($view, $sql) {
		$this->exec("CREATE OR REPLACE VIEW \"".$view."\" AS ".$sql);
	}
    
    public function updateFieldType($table, $field, $type) {
        $sql = "
			ALTER TABLE
				".$this->getBm()->quot($table)."
			ALTER COLUMN
				".$this->getBm()->quot($field)." TYPE ".$type."
			";
		$this->exec($sql);
    }
}
///home/yoreck/www/vihv-subprojects/bouncymelons/inc/drivers/SqliteDriver.php


class SqliteDriver extends Driver implements IDriver {
	
	public function getTableFields($table) {
		$list = $this->pdo->query("PRAGMA table_info(`".$table."`)")->fetchAll(\PDO::FETCH_ASSOC);
		$re = [];
		foreach($list as $item) {
			$re[] = [
				'field' => $item['name'],
				'type' => $item['type']
			];
		}
		return $re;
	}

	public function insertOrUpdate($table, $items) {
		$columns = array_keys($items);
		$values = array_values($items);
		$sql = "REPLACE INTO `".$table."`
				(`".implode("`,`",$columns)."`) 
				VALUES ('".implode("','",$values)."')";
		$re = $this->pdo->query($sql);
		if($re === false) {
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
			throw new EInsertFail($msg, $errint);
		}
		$id = $this->pdo->lastInsertId();
		if($id == 0) {
			throw new EInsertFail();
		}
		return $id;
	}
	
	public function createViewAs($view, $sql) {
		$this->exec("DROP VIEW IF EXISTS `".$view."`");
		$this->exec("CREATE VIEW `".$view."` AS ".$sql);
	}
	
	public function truncate($table) {
		//this is 2 step process, first - delete all data
		$this->exec("DELETE FROM `".$table."`");
		// vacuum?
		$this->exec("VACUUM");
	}

}
