<?php
namespace bm;



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

/**
 * \example basic-usage.php
 */
class BouncyMelons {
	
	const DEFAULT_ITEMS_PER_PAGE = 50;
	
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
			$this->setPDO(new \PDO($dsn, $user, $password, $options));
			if(!$this->getDriver() instanceof SqliteDriver) {
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
		return false !== $this->getPDO()->query("SELECT 1 FROM `".$name."`");
	}
	
	/**
	 * @return IDriver
	 */
	public function getDriver() {
		$driver = $this->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver == 'sqlite') {
			return new SqliteDriver($this->getPDO());
		}
		return new MysqlDriver($this->getPDO());
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
			$wr = "WHERE ".$where;
		}
		$ob = '';
		if(!empty($orderby)) {
			$ob = "ORDER BY ".$orderby;
		}
		$lim = '';
		if(!empty($limit)) {
			$lim = "LIMIT ".$limit;
		}
		$gb = '';
		if(!empty($groupBy)) {
			$gb = "GROUP BY ".$groupBy;
		}
		return $this->query("SELECT `".implode("`,`",$fields)."` FROM `".$table."` ".$wr." ".$gb." ".$ob." ".$lim);
	}
	
	/**
	 * Similar to select, but return only first result
	 * @param string $table table name
	 * @param string $where where clause
	 * @return associated array, which represent single row
	 */
	public function selectOne($table, $where) {
		return $this->queryOne("SELECT * FROM `".$table."` WHERE ".$where." LIMIT 1");
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
		$this->exec("DELETE FROM `".$table."` WHERE `id` = '".(int)$id."'");
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


}



interface IVModel {
	
	/**
	 * @return \bm\IList list model
	 */
	public function getListModel();
}



interface ISingle {
	
	
	public function getBm();
	public function setBm(BouncyMelons $bm);


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



interface IFilter {
	public function createWhere($fields);
	public function createOrderBy($fields);
	public function createLimit();
	public function getItemsPerPage();
}



interface IOrderByClause {
	
}



interface IGroupBy {
	public function __toString();
}



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



interface IDriver {
	
	public function __construct(\PDO $pdo);

	/**
	 * @param String $table table name
	 * @return array array of fields like [['field=>'fieldName','type'=>'fieldType'],[..],..]
	 */
	public function getTableFields($table);
	
	public function insertOrUpdate($table, $items);
	
	public function createViewAs($view, $sql);
	public function truncate($table);
}



class ERequiredFieldOptionNotSet extends Exception {}
class ERequiredFieldOptionWrong extends Exception {}

class Field implements IField{
	
	protected $name;
	protected $title;
	protected $sqlType;
	protected $options = [];
	
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
//		var_dump('in isRequired', $this->getOption('required'));//exit;
		return $this->getOption('required') == true;
	}

		/**
	 * @return get sql type, lowercased
	 */
	public function getSqlType() {
		if(empty($this->sqlType)) {
			return static::DEFAULT_SQL_TYPE;
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
	 * modify field-specific option
	 * 
	 * @param string $key
	 * @param any $value
	 */
	public function setOption($key, $value) {
		$this->options[$key] = $value;
	}

}



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



class TextField extends Field {
	const DEFAULT_SQL_TYPE = "text";
}


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



class StringField extends Field {
	
	const DEFAULT_SQL_TYPE = "varchar(255)";
	
}



class PasswordField extends StringField {
	const DEFAULT_SQL_TYPE = "varchar(255)";
}




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



class IntField extends Field {
	
	const DEFAULT_SQL_TYPE = "int(11)";
	
}



class DatetimeField extends Field{
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
		return date($format,strtotime($value));
	}
}



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



/**
 * handy for \<input type="checkbox"/>
 */
class BoolField extends Field {
	
	const DEFAULT_SQL_TYPE = "int(1)";
	
	public function beforeSet($value) {
		if($value == 'on') {
			$value = 1;
		}
		if(empty($value)) {
			$value = 0;
		}
		return parent::beforeSet($value);
	}
}



class VModel implements IVModel {
	
	protected $bm;
	protected $list;
	
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
	
	public function getTableName() {
		$list = $this->getListModel();
		if($list instanceof AutoList) {
			return str_replace("\\",".",strtolower(get_class($this)));
		}
		return $list->getTableName();
	}
	
	public function getViewName() {
		return $this->getListModel()->getViewName();
	}

	public static function getSlug() {
		return str_replace("\\",".",strtolower(static::class));
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



abstract class OrderByClause implements IOrderByClause {
	
	/**
	 * @var string
	 */
	protected $fieldName;

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



class Desc extends OrderByClause {
	
	public function get() {
		return "`".$this->getFieldName()."` DESC"; 
	}

}



class Asc extends OrderByClause {	
	public function get() {
		return "`".$this->getFieldName()."` ASC"; 
	}
}



class EFieldsNotDeclared extends Exception {}
class EBouncyMelonsInstanceNotSet extends Exception {}
class EFieldNotFound extends Exception {}
class ERequiredFieldNotSet extends Exception {}

abstract class Single implements ISingle {
	
	const TITLE = 'title';
	
	protected $data;

	/**
	 * @var BouncyMelons
	 */
	protected $bm;
	
	abstract public function declareFields();
	
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
		$cache->set($this->getSlug(),'fields',$fields);
		return $fields;
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
	
	public function getData() {
		return $this->data;
	}
	
	public function getDataForSave() {
		$re = [];
		foreach($this->getFields() as $field) {
			if(empty($this->data[$field->getName()])) {
				continue;
			}
			$re[$field->getName()] = $this->data[$field->getName()];
		}
		if(!empty($this->data['id'])) {
			$re['id'] = $this->data['id'];
		}
		return $re;
	}

	public function save() {
		$this->createTableIfNeeded();
		$bm = $this->getBm();
		$this->alterTable();
//		var_dump($this->getData());exit;
		$data = $this->getData();
		foreach($this->getFields() as $field) {
//			var_dump($field);
			if($field->isRequired()) {
//				var_dump('here we are'); 
//				var_dump($data[$field->getName()]);
				
				if(!isset($data[$field->getName()])) {
//					var_dump('throw?');
					throw new ERequiredFieldNotSet();
				}
				if($field instanceof IdField) {
					if((int)$data[$field->getName()] < 1) {
						throw new ERequiredFieldNotSet();
					}
				}
//				exit;
			}
		}
//		exit;
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
		return $this->getRaw('id');
	}
	
	public function setId($id) {
		$this->set('id', $id);
	}

	public function createTableIfNeeded() {
		$bm = $this->getBm();
		if(!$bm->tableExist($this->getTableName())) {
			$this->createTable();
		}
	}
	
	public function getTableName() {
		return str_replace("\\",".",strtolower(get_class($this)));
	}
	
	public function getSlug() {
		return str_replace("\\",".",strtolower(get_class($this)));
	}

	public function createTable() {
		$sqlFields = [];
		foreach($this->getFields() as $field) {
			if($field->getOption('calc')) {
				continue;
			}
			$sqlFields[] ="`".$field->getName()."` ".$field->getSqlType();
		}
		$bm = $this->getBm();
		
		$sql = "CREATE TABLE `".$this->getTableName()."`(
				".$bm->getCreatePrimaryId().",
				".implode(",",$sqlFields)."
				) ".$bm->getCreateDefaultCharset();
		$bm->exec($sql);
	}
	
	public function alterTable() {
		$bm = $this->getBm();
		$dbFields = $bm->getTableFields($this->getTableName());
		$dbFieldNames = array_column($dbFields, 'field');
		foreach($this->getFields() as $field) {
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
		$sql = "
			ALTER TABLE
				`".$this->getTableName()."`
			ADD
				`".$field->getName()."` ".$field->getSqlType()."
			";
		$bm = $this->getBm();
		$bm->exec($sql);
	}
	
	public function updateFieldType($field) {
		$sql = "
			ALTER TABLE
				`".$this->getTableName()."`
			MODIFY COLUMN
				`".$field->getName()."` ".$field->getSqlType()."
			";
		$bm = $this->getBm();
		$bm->exec($sql);
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
}



class AutoSingle extends Single implements ISingle {

	protected $list;
	
	public function declareFields() {
		return $this->list->declareFields();
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
}



class Driver {
	
	protected $pdo;
	
	public function __construct(\PDO $pdo) {
		$this->pdo = $pdo;
	}
	
	public function getPDO() {
		return $this->pdo;
	}
	
	public function exec($query) {
		$statement = $this->getPDO()->query($query);
		if(false === $statement) {
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
//			var_dump($query);
			throw new EExec($msg, $errint);
		}
		$statement = null;
	}
	
	/**
	 * 
	 * @param string $sql
	 * @return array of associated array, each sub array represent a row
	 * @throws ESelect
	 */
	public function query($sql) {
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
}



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
			$sqlItems[] = " `".$key."` = '".$value."'";
		}
		$sql = "INSERT INTO `".$table."` SET
			".implode(",",$sqlItems)."
			ON DUPLICATE KEY UPDATE ".implode(",",$sqlItems)." ";
		$re = $this->getPDO()->query($sql);
		if($re === false) {
			list($errno, $errint, $msg) = $this->getPDO()->errorInfo();
			throw new EInsertFail($msg, $errint);
		}
		if(!empty($items['id'])) {
			return $items['id'];
		}
		$id = $this->getPDO()->lastInsertId();
		if($id == 0) {
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
		$this->exec("TRUNCATE `".$table."`");
	}
}



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




class DataList {
	
	protected $vmodel;
	
	public function __construct(IVModel $vmodel) {
		$this->vmodel = $vmodel;
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

	/**
	 * @param IFilter $filter 
	 * @return array singular items
	 */
	public function find(IFilter $filter) {
		$this->createViewIfNeeded();
		$bm = $this->getBm();
		$filter->setBm($bm);
		$fields = $this->getFields();
		$where = $filter->createWhere($fields);
		$orderby = $filter->createOrderBy($fields);
		$limit = $filter->createLimit();

		$data = $bm->select($this->getViewName()
			, $this->getFieldNames()
			, $where, $limit, $orderby
			, $filter->createGroupBy()
			);
		
		$re = [];
		foreach($data as $itemData) {
			$item = $this->createSingle();
			$item->setData($itemData);
			$re[] = $item;
		}
		return $re;
	}
	
	public function getFieldNames() {
		$re = ['id'];
		foreach($this->getFields() as $field) {
			$re[] = $field->getName();
			if($field instanceof IdField) {
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
		return (int)$bm->queryParam("SELECT COUNT(*) FROM `".$this->getViewName()."` ".$wr);
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
		$this->getBm()->truncate($this->getTableName());
	}

	public function getOne($id, $column='id') {
		$item = $this->createSingle();
		$bm = $this->getBm();
		$data = $bm->selectOne($this->getTableName(),"`".$column."`='".$id."'");
		$item->setData($data);
		return $item;
	}
	
	public function readOne($id, $column='id') {
		$this->createViewIfNeeded();
		$item = $this->createSingle();
		$bm = $this->getBm();
		$data = $bm->selectOne($this->getViewName(),"`".$column."`='".$id."'");
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
		$single = $this->createSingle();
		$single->createTableIfNeeded();
		$single->alterTable();
	}

	public function declareView() {
		$joins = $this->createJoins();		
		$fieldsToSelect = $this->createFieldsToSelect();
		$sqlFields = implode(',',$fieldsToSelect);
		$this->createViewAs("
			SELECT
				`".$this->getTableName()."`.id, ".$sqlFields."
			FROM
				`".$this->getTableName()."`
			".$joins);
	}
	
	public function createViewAs($sql) {
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
					LEFT JOIN `".$field->getJoinTableName($list)."` 
						AS `".$list->getTableName().$field->getName()."`	
					ON (
						`".$list->getTableName().$field->getName()."`.id = `".$this->getTableName()."`.".$field->getName()."
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
			$temp = "`".$this->getTableName()."`.`".$field->getName()."`";
			if(!empty($prefix)) {
				$temp .=" AS `".$prefix.$field->getName()."`";
			}
			if($field instanceof IdField) {
				$viewModel = $bm->getVmBySlug($field->getOption('list'));
				$list = $viewModel->getListModel();
				$temp = $list->titleToSelect($field->getName(), $prefix);
				$temp .= ",`".$list->getTableName().$field->getName()."`.`id` AS `".$prefix.$field->getName()."_id`";
			}
			$fieldsToSelect[] = $temp;
		}
		return $fieldsToSelect;
	}
	
	public function titleToSelect($fieldName, $prefix = '') {
		$single = $this->createSingle();
		$titleField = $single->getTitleField();
		return "`".$this->getTableName().$fieldName."`.`".$titleField->getName()."` AS `".$prefix.$fieldName."`";
	}
	
	/**
	 * @return \bm\BouncyMelons
	 */
	public function getBm() {
		return $this->vmodel->getBm();
	}
	
//	public function getTableName() {
//		return $this->vmodel->getTableName();
//	}
	
	public function getSlug() {
		return $this->vmodel->getSlug();
	}
	
}



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
	
}



class GroupBy implements IGroupBy {
	
	private $fieldName;
	
	/**
	 * @param string $fieldName
	 */
	public function __construct($fieldName) {
		$this->fieldName = $fieldName;
	}
	
	public function __toString() {
		return "`".$this->fieldName."`";
	}
}



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
	private $bm;


	public function __constcruct() {}
	
	public function setBm($bm) {
		$this->bm = $bm;
	}
	
	public function getBm() {
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
	}
	
	public function setGroupBy($groupBy) {
		$this->groupBy = $groupBy;
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
		return $offset.','.$itemsperpage;
	}

	public function createOrderBy($fields) {
		return implode(",",$this->orderBy);
	}
	
	public function createGroupBy() {
		return implode(",",$this->groupBy);
	}
}



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


class FilterStrict extends FilterComplex {
	
	/**
	 * @param array of IField $fields
	 * @return string
	 */
	public function createWhere($fields) {
		$input = $this->getWhere();
		$re = [];
		foreach ($fields as $field) {
			if (empty($input[$field->getName()])) {
				continue;
			}
			if(is_array($input[$field->getName()])) {
				$re[] = "`".$field->getName()."` IN ('".implode("','",$input[$field->getName()])."')";
				continue;
			}
			$re[] = "`".$field->getName()."` = '".$input[$field->getName()]."'";
		}
		return implode(' AND ', $re);
	}
}


class FilterPermissive extends FilterComplex {
	
	/**
	 * @param array of IField $fields
	 * @return string
	 */
	public function createWhere($fields) {
		$input = $this->getWhere();
		$re = [];
		foreach ($fields as $field) {
			if($field instanceof IdField) {
				if(!empty($input[$field->getName()."_id"])) {
					$re[] = "`".$field->getName()."_id` = '".$input[$field->getName()."_id"]."'";
					continue;
				}
			}
			if($field instanceof BoolField) {
				if(isset($input[$field->getName()])) {
					$re[] = "`".$field->getName()."`='".$input[$field->getName()]."'";
				}
				continue;
			}
			if (empty($input[$field->getName()])) {
				continue;
			}
			if(is_array($input[$field->getName()])) {
				if(!empty($input[$field->getName()]['from'])
					&& !empty($input[$field->getName()]['to'])) {
					$re[] = "`".$field->getName()."` BETWEEN 
						'".$field->beforeSet($input[$field->getName()]['from'])."'
						AND '".$field->beforeSet($input[$field->getName()]['to'])."'";
					continue;
					}
				if(!empty($input[$field->getName()]['from'])) {
					$re[] = "`".$field->getName()."` = '".$input[$field->getName()]['from']."'";
					continue;
				}
				$re[] = "`".$field->getName()."` IN ('".implode("','",$input[$field->getName()])."')";
				continue;
			}
			$re[] = "`".$field->getName()."` LIKE '%".$input[$field->getName()]."%'";
		}
//		var_dump(implode(' AND ', $re));
		return implode(' AND ', $re);
	}
}



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
