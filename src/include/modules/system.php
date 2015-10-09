<?php

class SystemModule
{
	//this is used to store the result of any call
	private static $RESTART_CODE = "";
	public $result = null;
	public $version = "0.1a";

	//called always
	function __construct() {
	}

	//called when an ajax action is requested to this module
	public function processAction($action)
	{
		$this->result = Array();
		$this->result["debug"] = Array();

		if ($action == "ready")
			$this->actionCheckReady();
		else
		{
			//nothing
			$this->result["status"] = 0;
			$this->result["msg"] = 'no action performed';
		}

		$this->result["debug"] = getDebugLog();

		//the response is encoded in JSON on AJAX calls
		print json_encode( $this->result );
	}

	public function actionRestart()
	{
		$code = null;
		if(isset($_REQUEST["restart_code"]))
			$code = $_REQUEST["restart_code"];

		if(self::$RESTART_CODE == "" || $code != self::$RESTART_CODE)
		{
			$this->result["msg"] = "I can't let you do that, Dave";
			return;
		}

		$this->restartSystem();
	}


	public function actionCheckReady()
	{
		//check databse
		$database = getSQLDB();
		if(!$database)
		{
			$this->result["status"] = -2;
			$this->result["msg"] = "Cannot connect to DB";
			return;
		}

		if( $this->checkReady() != true)
		{
			$this->result["status"] = -1;
			return;
		}

		//gather system info
		$info = Array();
		$info["version"] = $this->version;
		dispatchEventToModules("onSystemInfo",$info);
		$this->result["info"] = $info;
		$this->result["status"] = 1;
	}

	public function checkReady()
	{
		//for every module
		$modules = loadModules("*");
		foreach($modules as $module)
		{
			if( method_exists($module,"isReady") )
			{
				if( $module->isReady() != 1 )
				{
					debug('System not ready, fail at '.get_class($module) );
					return false;
				}
			}
		}

		return true;
	}

	public function restartSystem()
	{
		$tmp = Array();
		debug("Restarting system" );
		dispatchEventToModules("preRestart",$tmp); //remove all
		dispatchEventToModules("restart",$tmp); //create tables and folders
		dispatchEventToModules("postRestart",$tmp); //fill stuff
		debug("System restarted" );
		return true;
	}

	public function upgradeSystem()
	{
		$tmp = Array();
		debug("Upgrading system" );
		dispatchEventToModules("upgrade",$tmp); //create tables and folders
		debug("System upgraded" );
		return true;
	}

	public function getSystemTables()
	{
		$database = getSQLDB();
		$query = "SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE '".DB_PREFIX."%'";
		$result = $database->query( $query );
		$table_names = Array();
		if($result == null)
			return null;

		while($table = $result->fetch_object())
			$table_names[] = $table->TABLE_NAME;

		return $table_names;
	}

	private static function delTree( $dir )
	{
		$path = "./";
		$files = array_diff(scandir($path . $dir), array('.','..'));
		foreach ($files as $file) {
		  (is_dir($path . "$dir/$file")) ? self::delTree("$dir/$file") : unlink($path . "$dir/$file");
		}
		return rmdir($path . $dir);
	} 

	public function backup( $filename )
	{
		//create backup folder
		if(!is_dir("../backup"))
		{
			mkdir( "../backup" );  
			chmod( "../backup", 0775);
		}

		if(is_file("../backup/".$filename.".sql"))
		{
			debug("There is another backup, delete it first","red");
			return false;
		}

		//get tables from DB, dump to Text file
		$table_names = $this->getSystemTables();

		//dump DB
		debug("Saving DB...");
		$cmd = "mysqldump -u".DB_USER." -p".DB_PASSWORD." ".DB_NAME." " . join(" ",$table_names) . " >  ../backup/".$filename.".sql";
		//debug( $cmd );
		exec( $cmd );

		//dump files...
		debug("Compressing FILES (this could take some time)...");
		$cmd = "tar -cvzf ../backup/".$filename.".tar.gz -C ". FILES_PATH ." .";
		exec( $cmd );

		return true;
	}

	public function restore($filename)
	{
		//create backup folder
		if(!is_dir("../backup") || !is_file("../backup/".$filename.".sql") || !is_file("../backup/".$filename.".tar.gz"))
		{
			debug("Backup not found","red");
			return false;
		}

		//clear DB: no need to
		/*
		$table_names = $this->getSystemTables();
		$database = getSQLDB();
		$query = "DROP TABLE IF EXISTS " . join(",",$table_names);
		//$result = $database->query( $query );
		*/

		//restore SQL
		$sql_data = file_get_contents("../backup/".$filename.".sql", true);
		//$result = $database->query( $sql_data );

		//unzip to folder
		debug("Decompressing FILES (this could take some time)...");
		if(1)
		{
			if(is_dir("../backup/files"))
				self::delTree( "../backup/files" );
			mkdir( "../backup/files" );  
			chmod( "../backup/files", 0775);
			$cmd = "tar -xvzf ../backup/".$filename.".tar.gz -C ../backup/files";
		}
		else
		{
			self::delTree( FILES_PATH );
			$cmd = "tar -xvzf ../backup/".$filename.".tar.gz -C " . FILES_PATH; 
		}
		exec( $cmd );

		return true;
	}


};

//make it public
registerModule("system", "SystemModule" );
?>