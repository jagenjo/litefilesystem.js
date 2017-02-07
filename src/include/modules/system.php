<?php

class SystemModule
{
	//this is used to store the result of any call
	private static $RESTART_CODE = "";
	public $result = null;
	public $version = "0.2";

	//called always
	function __construct() {
	}

	//called when an ajax action is requested to this module
	public function processAction($action)
	{
		$this->result = Array();
		$this->result["debug"] = Array();//??

		if ($action == "ready")
			$this->actionCheckReady();
		else if ($action == "backups")
			$this->actionListBackups();
		else if ($action == "createBackup")
			$this->actionCreateBackup();
		else if ($action == "restoreBackup")
			$this->actionRestoreBackup();
		else if ($action == "deleteBackup")
			$this->actionDeleteBackup();
		//else if ($action == "upgrade") //done from install.php
		//	$this->upgradeSystem();
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

	public function actionListBackups()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not allowed';
			return;
		}
		
		$login = getModule("user");
		$is_admin = $login->isAdmin( $_REQUEST["token"] );
		if(!$is_admin )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not admin';
			return;
		}

		$this->result["data"] = $this->getBackupsList();
	}

	public function actionCreateBackup()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not allowed';
			return;
		}

		if(!isset($_REQUEST["name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'param missing';
			return;
		}
		
		$login = getModule("user");
		$is_admin = $login->isAdmin( $_REQUEST["token"] );
		if(!$is_admin )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not admin';
			return;
		}

		if( !$this->createBackup( $_REQUEST["name"] ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'backup not created';
			return;
		}

		$this->result["status"] = 1;
		$this->result["data"] = $this->getBackupsList();
		$this->result["msg"] = 'backup created';
	}

	public function actionRestoreBackup()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not allowed';
			return;
		}

		if(!isset($_REQUEST["name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'param missing';
			return;
		}

		$login = getModule("user");
		$is_admin = $login->isAdmin( $_REQUEST["token"] );
		if(!$is_admin )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not admin';
			return;
		}

		if( !$this->restoreBackup( $_REQUEST["name"] ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'backup not restored';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'backup restored';
	}

	public function actionDeleteBackup()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not allowed';
			return;
		}

		if(!isset($_REQUEST["name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'param missing';
			return;
		}

		$login = getModule("user");
		$is_admin = $login->isAdmin( $_REQUEST["token"] );
		if(!$is_admin )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not admin';
			return;
		}

		if( !$this->deleteBackup( $_REQUEST["name"] ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'backup not restored';
			return;
		}

		$this->result["status"] = 1;
		$this->result["data"] = $this->getBackupsList();
		$this->result["msg"] = 'backup deleted';
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
		//check global info
		$owner = posix_getgrgid( filegroup( __FILE__ ) );
		if($owner)
		{
			if($owner["name"] != "www-data")
			{
				debug("The group of this script is not 'www-data', this could be a problem. Ensure that all files inside this folder belong to the group 'www-data' by running this command from inside the folder: su chown -R :www-data *");
			}
		}

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
		if(!is_dir($path . $dir))
			return;
		$files = array_diff(scandir($path . $dir), array('.','..'));
		foreach ($files as $file) {
		  (is_dir($path . "$dir/$file")) ? self::delTree("$dir/$file") : unlink($path . "$dir/$file");
		}
		return rmdir($path . $dir);
	} 

	public function getBackupsList()
	{
		$result = Array();
		if(!is_dir(BACKUPS_FOLDER))
			return $result;

		foreach ( glob( BACKUPS_FOLDER . "/*.tar.gz") as $filename )
		{
			$name = basename($filename,".tar.gz");
			$time = filemtime($filename);
			$link = BACKUPS_FOLDER . "/" . $name . ".tar.gz";
			$size = filesize( $link );
			$backup_info = Array( "time" => $time, "name" => $name, "pretty_time" => date ("F d Y H:i:s", $time ), "size" => $size, "link"=>$link );
			$result[] = $backup_info;
		}

		return $result;

		/*
		$files = array_diff( scandir( BACKUPS_FOLDER ), array('.','..') );
		foreach ($files as $file) 
		{
			if (is_dir("$dir/$file") && $file[0] != "_" )
			{
				$folders[$file] = $this->getSubfolders("$dir/$file");
			}
		}
		*/
	}

	public function createBackup( $filename )
	{
		if( !preg_match('/^[0-9a-zA-Z\_\- ... ]+$/', $filename) )
		{
			debug("Invalid backup name");
			return false;
		}

		//create backup folder
		if(!is_dir(BACKUPS_FOLDER))
		{
			mkdir( BACKUPS_FOLDER );  
			chmod( BACKUPS_FOLDER, 0775);
		}

		if(is_file( BACKUPS_FOLDER ."/".$filename.".sql"))
		{
			debug("There is another backup, delete it first");
			return false;
		}

		//get tables from DB, dump to Text file
		$table_names = $this->getSystemTables();

		//dump DB
		debug("Saving DB...");
		$cmd = "mysqldump -u".DB_USER." -p".DB_PASSWORD." ".DB_NAME." " . join(" ",$table_names) . " >  ../backup_db.sql"; //--skip-add-drop-table
		//debug( $cmd );
		exec( $cmd );

		//dump files...
		debug("Compressing FILES (this could take some time)...");
		$cmd = "tar -cvzf ".BACKUPS_FOLDER."/".$filename.".tar.gz -C ". FILES_PATH ." . ../backup_db.sql";
		exec( $cmd );

		unlink( "../backup_db.sql" );

		return true;
	}

	public function deleteBackup( $filename )
	{
		if( !preg_match('/^[0-9a-zA-Z\_\- ... ]+$/', $filename) )
		{
			debug("Invalid backup name");
			return false;
		}

		//create backup folder
		if(!is_dir( BACKUPS_FOLDER ) || !is_file(BACKUPS_FOLDER . "/" . $filename . ".tar.gz" ))
		{
			debug("Backup not found","red");
			return false;
		}

		unlink(BACKUPS_FOLDER . "/" . $filename . ".tar.gz");

		return true;
	}

	public function restoreBackup( $filename, $dump_only = false )
	{
		global $database;

		if( !preg_match('/^[0-9a-zA-Z\_\- ... ]+$/', $filename) )
		{
			debug("Invalid backup name");
			return false;
		}

		//create backup folder
		if(!is_dir( BACKUPS_FOLDER ) || !is_file(BACKUPS_FOLDER . "/" . $filename . ".tar.gz" ))
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

		//unzip to folder
		debug("Decompressing FILES (this could take some time)...");
		if( $dump_only ) //dump to backup folder
		{
			if(is_dir(BACKUPS_FOLDER . "/files"))
				self::delTree( BACKUPS_FOLDER . "/files" );
			mkdir( BACKUPS_FOLDER . "/files" );  
			chmod( BACKUPS_FOLDER . "/files", 0775);
			$cmd = "tar -xvzf " . BACKUPS_FOLDER . "/".$filename.".tar.gz -C ".BACKUPS_FOLDER."/files";
			exec( $cmd );
		}
		else //delete old content
		{
			/*
			$result = $database->query( $sql_data );
			if(!$result)
			{
				debug("Problem loading the DATABASE:\n " . $database->error);
				return false;
			}
			*/

			//extract files
			self::delTree( FILES_PATH );
			mkdir( FILES_PATH );  
			chmod( FILES_PATH, 0775);
			$cmd = "tar -xvzf " . BACKUPS_FOLDER."/".$filename.".tar.gz -C " . FILES_PATH; 
			exec( $cmd );

			if(!is_file( FILES_PATH."/backup_db.sql" ))
			{
				debug("ERROR: DATABASE SQL NOT FOUND" );
				return false;
			}

			//load SQL
			$cmd = "mysql -u".DB_USER." -p".DB_PASSWORD." ".DB_NAME." < ".FILES_PATH."/backup_db.sql";
			debug( $cmd );
			exec( $cmd );
			//unlink( FILES_PATH."/backup_db.sql" );
		}


		return true;
	}


};

//make it public
registerModule("system", "SystemModule" );
?>