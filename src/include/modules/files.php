<?php

class FilesModule
{
	// List of internal configurable variables
	//********************************************
	private static $MAX_UNITS_PER_USER = 5; 
	private static $MAX_USERS_PER_UNIT = 1000;
	private static $DEFAULT_UNIT_SIZE = 5000000;
	private static $UNIT_MIN_SIZE = 1048576; //in bytes
	private static $UNIT_MAX_SIZE = 509715200; //in bytes
	private static $PREVIEW_IMAGE_SIZE = 128; //in pixels
	private static $MAX_PREVIEW_FILE_SIZE = 300000;//in bytes
	private static $ALLOW_REMOTE_FILES = ALLOW_REMOTE_FILE_DOWNLOADING; //allow to download remote files
	//private static $RESTART_CODE = "doomsday"; //internal module restart password

	//this is used to store the result of any call
	public $result = null;
	public $last_error = "";


	public $users_created_limit = 0;

	//called always
	function __construct() {
	}

	public function processAction($action)
	{
		header('Content-Type: application/json');

		$this->result = Array();
		$this->result["debug"] = Array();

		debug($action);

		switch($action)
		{
			case "createUnit": $this->actionCreateUnit(); break; //create a new unit
			case "getUnits": $this->actionGetUnits(); break; //get files inside one folder
			case "inviteUserToUnit": $this->actionInviteUserToUnit(); break; //create a new unit
			case "removeUserFromUnit": $this->actionRemoveUserFromUnit(); break; //create a new unit
			case "joinUnit": $this->actionJoinUnit(); break; //join an existing unit
			case "leaveUnit": $this->actionLeaveUnit(); break; //leave an existing unit
			case "getUnitInfo": $this->actionGetUnitInfo(); break; //get info about all the users in a unit
			case "setUnitInfo": $this->actionSetUnitInfo(); break; //get info about all the users in a unit
			case "deleteUnit": $this->actionDeleteUnit(); break; //create a new unit
			case "getFolders":	$this->actionGetFolders(); break; //get folders tree
			case "createFolder": $this->actionCreateFolder(); break; //create a folder
			case "downloadFolder": $this->actionDownloadFolder(); break; //download a folder content in a zip
			case "deleteFolder": $this->actionDeleteFolder(); break; //create a new unit
			case "moveFolder": $this->actionMoveFolder(); break; //move folder
			case "getFilesInFolder": $this->actionGetFilesInFolder(); break; //get files inside one folder
			case "getFilesTree": $this->actionGetFilesTree(); break; //get all files info
			case "searchFiles": $this->actionSearchFiles(); break; //get files matching a search
			case "getFileInfo": $this->actionGetFileInfo(); break; //get metainfo about one file
			case "uploadFile": $this->actionUploadFile(); break; //upload a file
			case "uploadRemoteFile": $this->actionUploadRemoteFile(); break; //upload a file from URL
			case "deleteFile": 	$this->actionDeleteFile(); break; //delete a file (by id)
			case "moveFile": $this->actionMoveFile(); break; //change a file (also rename)
			case "copyFile": $this->actionCopyFile(); break; //make a copy of a file
			case "updateFile": $this->actionUpdateFile(); break; //replace a file content
			case "updateFilePart": $this->actionUpdateFilePart(); break; //replace a file content
			case "updateFilePreview": $this->actionUpdateFilePreview(); break; //update file preview image
			case "updateFileInfo":$this->actionUpdateFileInfo(); break; //update file meta info
			case "debug":$this->actionDebug(); break; //update file meta info
			default:
				//nothing
				$this->result["status"] = 0;
				$this->result["msg"] = 'action unknown: ' . $action;
		}

		$this->result["debug"] = getDebugLog();

		//the response is encoded in JSON on AJAX calls
		print json_encode($this->result);
	}

	//get units available to user
	public function actionGetUnits()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$units = $this->getUserUnits( $user->id );

		if(isset($_REQUEST["folders"]) && $_REQUEST["folders"] == true)
		{
			foreach($units as $i => $unit)
			{
				 $unit->folders = $this->getFolders( $unit->name );
			}
		}

		$this->result["msg"] = "retrieving units";
		$this->result["status"] = 1;
		$this->result["data_type"] = "units";
		$this->result["data"] = $units;
	}

	public function actionCreateUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$max_units = self::$MAX_UNITS_PER_USER;

		if(!isset($_REQUEST["unit_name"]) || !isset($_REQUEST["size"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//check how many units does he have
		$units = $this->getUserUnits($user->id);
		if($max_units > 0 && count($units) >= $max_units)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many units';
			return;
		}

		$size = intval($_REQUEST["size"]);
		if($size < self::$UNIT_MIN_SIZE || $size > self::$UNIT_MAX_SIZE )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'invalid size';
			return;
		}

		if($user->used_space + $size > $user->total_space )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought free space';
			return;
		}

		//create unit and change user free space
		$unit = $this->createUnit( $user->id, null, $size, $unit_name, true );

		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'error creating unit';
			return;
		}

		//update user
		$user = getModule("user")->getUser( $user->id );

		$this->result["status"] = 1;
		$this->result["unit"] = $unit;
		$this->result["user"] = $user;
		$this->result["msg"] = 'unit created';
		return;
	}

	public function actionInviteUserToUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$mode = "READ";
		$max_units = self::$MAX_UNITS_PER_USER;

		if(!isset($_REQUEST["unit_name"]) || !isset($_REQUEST["username"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, !$user->roles["admin"] ? $user->id : -1 );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed: ' . $unit_name;
			return;
		}

		//check how many users does this unit have
		$users = $this->getUnitUsers( $unit->id );
		if( count($users) >= self::$MAX_USERS_PER_UNIT && !$user->roles["admin"])
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many users in this unit';
			return;
		}

		//get other user
		$usermodule = getModule("user");
		$target_username = $_REQUEST["username"];
		$target_user = null;
		if( filter_var($target_username, FILTER_VALIDATE_EMAIL) )
			$target_user = $usermodule->getUserByMail($target_username);
		else
			$target_user = $usermodule->getUserByName($target_username);

		if(!$target_user)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user not found';
			return;
		}

		//check how many units does he have
		$units = $this->getUserUnits($target_user->id);
		if($max_units > 0 && count($units) >= $max_units && !$user->roles["admin"])
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many units';
			return;
		}

		//is already in unit
		foreach($units as $i => $u)
		{
			if($u->name == $unit_name)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'user already in unit';
				return;
			}
		}

		//add privileges
		if( !$this->setPrivileges($unit->id, $target_user->id, $mode ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem giving rights to unit';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'user added';
		return true;
	}

	public function actionRemoveUserFromUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$mode = "READ";
		$max_units = self::$MAX_UNITS_PER_USER;

		if(!isset($_REQUEST["unit_name"]) || !isset($_REQUEST["username"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get other user
		$usermodule = getModule("user");
		$target_username = $_REQUEST["username"];
		$target_user = null;
		if( filter_var($target_username, FILTER_VALIDATE_EMAIL) )
			$target_user = $usermodule->getUserByMail($target_username);
		else
			$target_user = $usermodule->getUserByName($target_username);

		if(!$target_user)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user not found';
			return;
		}

		if($user->id == $target_user->id)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'cannot be removed from your own unit';
			return;
		}


		//is already in unit
		$units = $this->getUserUnits($target_user->id);
		$found = false;
		foreach($units as $i => $u)
		{
			if($u->name == $unit_name)
			{
				$found = true;
				break;
			}
		}

		if(!$found)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user not belong to this unit';
			return;
		}


		//add privileges
		if( !$this->setPrivileges($unit->id, $target_user->id, null ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem removing rights to unit';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'user removed';
		return true;
	}

	public function actionJoinUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$mode = "READ";
		$max_units = self::$MAX_UNITS_PER_USER;

		if(!isset($_REQUEST["invite_token"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$invite_token = $_REQUEST["invite_token"];
		if($invite_token == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong invite token';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByToken( $invite_token );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found';
			return;
		}

		//check how many users does this unit have
		$users = $this->getUnitUsers( $unit->id );
		if( count($users) >= self::$MAX_USERS_PER_UNIT && !$user->roles["admin"])
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many users in this unit';
			return;
		}

		//check how many units does he have
		$units = $this->getUserUnits($user->id);
		if($max_units > 0 && count($units) >= $max_units && !$user->roles["admin"])
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many units';
			return;
		}

		//is already in unit
		foreach($units as $i => $u)
		{
			if($u->invite_token == $invite_token)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'user already in unit';
				return;
			}
		}

		//add privileges
		if( !$this->setPrivileges($unit->id, $user->id, $mode ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem giving rights to unit';
			return;
		}

		$this->result["status"] = 1;
		$this->result["user"] = $user;
		$this->result["msg"] = 'user joined the unit';
		return true;
	}


	public function actionLeaveUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit_name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}


		if($unit->author_id == $user->id)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'you cannot leave a unit that belongs to you';
			return;
		}

		//is already in unit
		$units = $this->getUserUnits($user->id);
		$found = false;
		foreach($units as $i => $u)
		{
			if($u->name == $unit_name)
			{
				$found = true;
				break;
			}
		}

		if(!$found)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user is not in that unit';
			return;
		}

		//remove privileges
		if( !$this->setPrivileges( $unit->id, $user->id, null ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem removing rights from unit';
			return;
		}

		$this->result["status"] = 1;
		$this->result["user"] = $user;
		$this->result["msg"] = 'user leave unit';
		return true;
	}

	public function actionDeleteUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit_name"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		if($unit->author_id != $user->id)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit can only be removed by creator';
			return;
		}

		// min units 
		$units = $this->getUserUnits($user->id, true);
		if(count($units) == 1)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem deleting unit';
			return;
		}

		if( !$this->deleteUnit( $unit->id, true ))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem deleting unit';
			return;
		}

		$user->used_space -= $unit->total_size;

		$this->result["status"] = 1;
		$this->result["msg"] = 'unit deleted';
		$this->result["user"] = $user;
		return;
	}

	public function actionGetUnitInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit_name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get users
		if($user->id == $unit->author_id)
		{
			$users = $this->getUnitUsers( $unit->id );
			$this->result["users"] = $users;
			$unit->users = $users;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'unit info';
		$this->result["unit"] = $unit;
	}

	public function actionSetUnitInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit_name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		$total_size = -1;
		
		if(isset($_REQUEST["total_size"]))
			$total_size = intval($_REQUEST["total_size"]);
		if($total_size <= 0)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit size cannot be zero';
			return;
		}

		debug("totalsize: " . $total_size );
		if($total_size != -1 && $total_size != $unit->total_size)
		{
			if ($total_size < self::$UNIT_MIN_SIZE || $total_size > self::$UNIT_MAX_SIZE)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'Invalid Size: '.$total_size.' max size is ' . self::$UNIT_MAX_SIZE;
				return;
			}


			if ($total_size < $unit->used_size)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'cannot resize below content size, remove files';
				return;
			}

			//change in total size
			$diff = $total_size - $unit->total_size;

			if($user->used_space + $diff > $user->total_space)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'user doesnst have enough space available';
				return;
			}
			
			//reduce user free space
			$usermodule = getModule("user");
			if( !$usermodule->changeUserUsedSpace( $user->id, $diff, true) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'problem changing user used space';
				return;
			}
			debug("user used space changed");

			if( !$this->changeUnitTotalSize($unit->id, $total_size) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'problem changing unit size';
				return;
			}
			debug("unit total size changed");

			$unit->total_size += $diff;
			$user->used_space += $diff;
		}

		if(isset($_REQUEST["metadata"]))
		{
			if($this->setUnitMetadata( $unit->id, $_REQUEST["metadata"] ))
			{
				$unit->metadata = $_REQUEST["metadata"];
				debug("unit metadata changed");
			}
			else
				debug("problem changing unit metadata");
		}
		
		$this->result["status"] = 1;
		$this->result["msg"] = 'unit info changed';
		$this->result["unit"] = $unit;
		$this->result["user"] = $user;
	}


	public function actionGetFolders()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $_REQUEST["unit"], $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionGetFolders check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		$this->result["msg"] = "retrieving tree";
		$this->result["status"] = 1;
		$this->result["data"] = $this->getFolders( $unit->name );
	}

	public function actionGetFilesTree()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["folder"]) || !isset($_REQUEST["unit"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$folder = $_REQUEST["folder"];
		$unit_id = intval($_REQUEST["unit"]);

		$unit = $this->getUnitByName( $unit_id, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionGetFilesTree check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get all files
		$this->result["status"] = 1;
		$this->result["data"] = $this->getFilesFromDB( $unit_id, "", true );
		$this->result["msg"] = 'files tree';
	}

	public function actionCreateFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$unit = null;
		$folder = null;

		if(isset($_REQUEST["fullpath"]))
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"], true );	
			if(!$path_info)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'fullpath contains invalid characters';
				return;			
			}
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
		}
		else if(isset($_REQUEST["folder"]) && isset($_REQUEST["unit"]))
		{
			$unit_name = $_REQUEST["unit"];
			$folder = $_REQUEST["folder"];
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionCreateFolder getUnitByName");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		if(!$unit->mode || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'cannot write';
			return;
		}

		if( strpos($folder,"..") != FALSE )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong folder name';
			return;
		}

		$fullpath = $this->clearPathName( $unit->name . "/". $folder );

		self::createFolder( $fullpath );
		$this->result["msg"] = "folder created";
		$this->result["data"] = $fullpath;
		$this->result["status"] = 1;
	}

	public function actionDownloadFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$unit = null;
		$folder = null;

		if(isset($_REQUEST["fullpath"]))
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"], true );
			if(!$path_info)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'fullpath contains invalid characters';
				return;			
			}
			
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
		}
		else if(isset($_REQUEST["folder"]) && isset($_REQUEST["unit"]))
		{
			$unit_name = $_REQUEST["unit"];
			$folder = $_REQUEST["folder"];
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionDeleteFolder getUnitByName: " . $unit_name);
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		if(!$unit->mode || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'cannot download with only read privileges';
			return;
		}

		//DELETE
		$dataurl = $this->downloadFolder( $unit, $folder );
		if( !$dataurl )
		{
			$this->result["msg"] = "folder cannot be downloaded";
			$this->result["status"] = -1;
			return;
		}

		$this->result["msg"] = "folder ready to download";
		$this->result["unit"] = $this->getUnit( $unit->id );
		$this->result["data"] = $dataurl;
		$this->result["status"] = 1;
	}

	public function actionDeleteFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$unit = null;
		$folder = null;

		if(isset($_REQUEST["fullpath"]))
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"], true );
			if(!$path_info)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'fullpath contains invalid characters';
				return;			
			}
			
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
		}
		else if(isset($_REQUEST["folder"]) && isset($_REQUEST["unit"]))
		{
			$unit_name = $_REQUEST["unit"];
			$folder = $_REQUEST["folder"];
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionDeleteFolder getUnitByName: " . $unit_name);
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		if(!$unit->mode || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'cannot delete';
			return;
		}

		//DELETE
		if( !$this->deleteFolder( $unit, $folder ) )
		{
			$this->result["msg"] = "folder cannot be deleted";
			$this->result["status"] = -1;
			return;
		}

		$this->result["msg"] = "folder deleted";
		$this->result["unit"] = $this->getUnit( $unit->id );
		$this->result["data"] = $fullpath;
		$this->result["status"] = 1;
	}

	public function actionMoveFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$unit = null;
		$folder = null;

		if(!isset($_REQUEST["fullpath"]) || !isset($_REQUEST["target_fullpath"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//check origin
		$fullpath = $_REQUEST["fullpath"];
		$folder_info = $this->parsePath( $_REQUEST["fullpath"] );
		if(!$folder_info)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'fullpath contains invalid characters';
			return;			
		}

		$origin_unit = $this->getUnitByName( $origin_info->unit, $user->id );
		if(!$origin_unit || $origin_unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionMoveFolder check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}


		//check privileges in destination unit
		$target_fullpath = $_REQUEST["target_fullpath"];
		$target_info = $this->parsePath( $target_fullpath );
		if(!$target_info)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'target fullpath contains invalid characters';
			return;			
		}

		$target_unit = $this->getUnitByName( $target_info->unit, $user->id );
		if(!$target_unit || $target_unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionMoveFolder check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//MOVE
		if( !$this->moveFolder( $target_info->unit . "/" . $target_info->folder, $folder_info->unit . "/" . $folder_info->folder) )
		{
			$this->result["msg"] = "folder cannot be moved";
			$this->result["status"] = -1;
			return;
		}

		$this->result["msg"] = "folder moved";
		$this->result["unit"] = $this->getUnit( $target_unit->id );
		$this->result["status"] = 1;
	}

	public function actionGetFilesInFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(isset($_REQUEST["fullpath"]))
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"], true );
			if(!$path_info)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'fullpath contains invalid characters';
				return;			
			}
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
		}
		else if(isset($_REQUEST["folder"]) && isset($_REQUEST["unit"]))
		{
			$unit_name = $_REQUEST["unit"];
			$folder = $_REQUEST["folder"];
			$folder = $this->clearPathName( $folder );
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionGetFilesInFolder getUnitByName: " . $unit_name);
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get files from DB
		$dbfiles = $this->getFilesFromDB($unit->id, $folder);
		if(!$dbfiles)
		{
			$this->result["msg"] = "no files";
			$this->result["status"] = 1;
			return;
		}

		//remove private info
		foreach($dbfiles as $i => $file)
		{
			$file->unit = $unit_name;
			unset( $file->id );
			unset( $file->author_id );
			unset( $file->unit_id );
			//unset( $file->folder );
		}

		$this->result["msg"] = "retrieving files";
		$this->result["status"] = 1;
		$this->result["unit"] = $unit_name;
		$this->result["folder"] = $folder;
		$this->result["data"] = $dbfiles;
	}


	public function actionSearchFiles()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$category = null;
		$filename = null;

		if(isset($_REQUEST["category"]))
			$category = $_REQUEST["category"];
		else if(isset($_REQUEST["filename"]))
			$filename = $_REQUEST["filename"];
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//get units of user
		$units = $this->getUserUnits($user->id);

		$dbfiles = Array();
		foreach($units as $i => $unit)
		{
			if($category)
				$found = $this->searchFilesFromDBByCategory( $unit->id, $category );
			else
				$found = $this->searchFilesFromDBByFilename( $unit->id, $filename );
			foreach($found as $j => $file)
			{
				$file->unit = $unit->name;
				unset($file->id);
				unset($file->unit_id);
				unset($file->author_id);
			}
			$dbfiles = array_merge($dbfiles, $found);
		}
		$this->result["msg"] = "retrieving files";
		$this->result["status"] = 1;
		$this->result["data"] = $dbfiles;
	}


	public function actionGetFileInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["fullpath"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'no fullpath supplied';
			return;
		}

		$fullpath = $_REQUEST["fullpath"];

		//get file from DB
		$file = $this->getFileInfoByFullpath($fullpath);
		if(!$file)
		{
			$this->result["msg"] = "no file found";
			$this->result["status"] = 1;
			return;
		}

		$this->result["fullpath"] = $fullpath;
		$this->result["msg"] = "retrieving file";
		$this->result["status"] = 1;
		$this->result["data"] = $file;
	}

	public function actionUploadFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		//"data" is not tested here because it could come in $_FILES
		$unit_name = "";
		$folder = "";
		$filename = "";
		$fullpath = "";
		$bytes = 0;

		if( isset($_REQUEST["fullpath"]) )
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"] );
			if(!$path_info)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'fullpath contains invalid characters';
				return;			
			}
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
			$filename = $path_info->filename;
			$fullpath = $path_info->fullpath;
		}
		else if(isset($_REQUEST["unit"]) && $_REQUEST["unit"] && isset($_REQUEST["folder"]) && isset($_REQUEST["filename"]) && $_REQUEST["filename"] )
		{
			$unit_name = $_REQUEST["unit"];
			if(!$this->validateFilename($unit_name))
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'unit contains invalid characters';
				return;
			}
			$folder = $_REQUEST["folder"];
			if( !$this->validateFolder($folder) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'folder contains invalid characters: ' . $folder;
				return;
			}
			$filename = $_REQUEST["filename"];
			if( !$this->validateFilename($filename) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'filename contains invalid characters';
				return;
			}
			$fullpath =  $this->clearPathName( $unit_name . "/" . $folder . "/" . $filename );
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//check unit
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionUploadFile getUnitByName");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}
		//debug("Unit found: " . $unit->id );

		//check privileges
		if(!$unit->mode || $unit->mode == "" || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUploadFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		// FILE DATA RETRIEVING ***************
		$encoding = "text";
		if( isset($_REQUEST["encoding"]) && $_REQUEST["encoding"] != "")
			$encoding = $_REQUEST["encoding"];

		if( $encoding == "file" )
		{
			$data = $this->readFileData("data");
			if ($data === false )
				return;
		}
		else if(!isset($_REQUEST["data"]) )
		{
			if(!isset($_REQUEST["total_size"]) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'data params missing';
				return;
			}
			$encoding = null;
			$data = "";
			$bytes = intval( $_REQUEST["total_size"] );
			debug("creating empty file");
		}
		else
			$data = $_REQUEST["data"];
		if($encoding == "base64")
			$data = base64_decode($data);
		//***************

		//debug("Filesize: " . strlen($data) );

		$preview = null;
		if( isset($_REQUEST["preview"]) )
			$preview = $_REQUEST["preview"];

		$metadata = "";
		if(isset($_REQUEST["metadata"]))
			$metadata = $_REQUEST["metadata"];

		//clean up the path name
		$path_info = pathinfo($folder);
		$dirname = $path_info["dirname"];
		$folder = $dirname . "/" . $path_info["basename"];
		if( substr($folder, 0, 2) == "./" )
			$folder = substr($folder, 2);

		$category = "";
		if(isset($_REQUEST["category"]))
			$category = $_REQUEST["category"];

		//check storage space stuff
		if( $data )
			$bytes = strlen( $data );

		$unit_size = $unit->used_size;
		$diff = $bytes; //difference in used space between before storing and after storing the file
		$file_exist = $this->fileExist($fullpath);

		if( $file_exist ) //file exist: overwrite
		{
			$old_file_size = $this->getFileSize( $fullpath );
			$diff = $bytes - $old_file_size;
		}

		if( $unit->used_size + $diff > $unit->total_size )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "Not enough free space";
			return;
		}

		//store file
		$file_id = $this->storeFile( $user->id, $unit->id, $folder, $filename, $data, $category, $metadata, $bytes );
		if($file_id == null)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//update unit used size
		if($diff != 0)
		{
			if(!$this->changeUnitUsedSize( $unit->id, $diff, true ) )
				debug("Something went wrong when changing used size: " . $diff);
			$unit_size += $diff;
		}

		if($preview)
		{
			if( $this->updateFilePreview($file_id, $preview) )
				debug("Saved preview");
		}
		else if( isset($_REQUEST["generate_preview"]) && $_REQUEST["generate_preview"] )
		{
			if($this->generateFilePreview( $file_id ))
				debug("Generated preview");
		}

		$unit->used_size = $unit_size;

		$this->result["unit"] = $unit;
		$this->result["status"] = 1;
		$this->result["msg"] = 'file saved';
		$this->result["id"] = $file_id;
		$this->result["fullpath"] = $fullpath;
	}


	public function actionUploadRemoteFile()
	{
		global $categories_by_type;
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!self::$ALLOW_REMOTE_FILES)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'disabled';
			return;
		}

		$unit_name = "";
		$folder = "";
		$filename = "";
		$fullpath = "";

		if( !isset($_REQUEST["url"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if( isset($_REQUEST["fullpath"]) )
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"] );
			if(!$path_info)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'fullpath contains invalid characters';
				return;			
			}

			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
			$filename = $path_info->filename;
			$fullpath = $path_info->fullpath;
		}
		else if(isset($_REQUEST["unit"]) && $_REQUEST["unit"] && isset($_REQUEST["folder"]) && isset($_REQUEST["filename"]) && $_REQUEST["filename"] )
		{
			$unit_name = $_REQUEST["unit"];
			if(!$this->validateFilename($unit_name))
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'unit contains invalid characters';
				return;
			}
			$folder = $_REQUEST["folder"];
			if( !$this->validateFolder($folder))
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'folder contains invalid characters';
				return;
			}
			$filename = $_REQUEST["filename"];
			if(!$this->validateFilename($filename))
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'filename contains invalid characters';
				return;
			}
			$fullpath =  $this->clearPathName( $unit_name . "/" . $folder . "/" . $filename );
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//check unit
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionUploadFile getUnitByName");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//check privileges
		if(!$unit->mode || $unit->mode == "" || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUploadFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		$url = $_REQUEST["url"];
		$url_info = $this->parsePath( $url );
		if(!$this->validateExtension( $url_info->filename ) )
		{
			debug("Filename contains invalid extension: " . $url_info->filename);
			$this->last_error = "Invalid url";
			return null;
		}

		$file_data = $this->downloadFile($url);
		if ( !$file_data )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'file not found';
			return;
		}

		$data = $file_data->data;

		$preview = null;
		if( isset($_REQUEST["preview"]) )
			$preview = $_REQUEST["preview"];

		$metadata = "";
		if(isset($_REQUEST["metadata"]))
			$metadata = $_REQUEST["metadata"];

		//clean up the path name
		$path_info = pathinfo($folder);
		$dirname = $path_info["dirname"];
		$folder = $dirname . "/" . $path_info["basename"];
		if( substr($folder, 0, 2) == "./" )
			$folder = substr($folder, 2);

		$category = "";
		if(isset($_REQUEST["category"]))
			$category = $_REQUEST["category"];
		else if( isset($categories_by_type[  $file_data->type ] ) )
			$category = $categories_by_type[  $file_data->type ];

		//check storage space stuff
		$bytes = strlen( $data );
		$unit_size = $unit->used_size;
		$diff = $bytes; //difference in used space between before storing and after storing the file
		if( $this->fileExist($fullpath) ) //file exist: overwrite
		{
			$old_file_size = $this->getFileSize( $fullpath );
			$diff = $bytes - $old_file_size;
		}

		if( $unit->used_size + $diff > $unit->total_size )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "Not enough free space";
			return;
		}

		//store file: extension is validated inside
		$file_id = $this->storeFile( $user->id, $unit->id, $folder, $filename, $data, $category, $metadata );
		if($file_id == null)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//update unit used size
		if(!$this->changeUnitUsedSize( $unit->id, $diff, true ))
			debug("Something went wrong when changing used size: " . $diff);
		$unit_size += $diff;

		if($preview)
		{
			if( $this->updateFilePreview($file_id, $preview) )
				debug("Saved preview");
		}
		else //if( isset($_REQUEST["generate_preview"]) && $_REQUEST["generate_preview"] )
		{
			if($this->generateFilePreview( $file_id ))
				debug("Generated preview");
		}

		$unit->used_size = $unit_size;

		$this->result["unit"] = $unit;
		$this->result["status"] = 1;
		$this->result["msg"] = 'file saved';
		$this->result["id"] = $file_id;
		$this->result["fullpath"] = $fullpath;
	}


	public function actionDeleteFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$fullpath = null;

		if(isset($_REQUEST["multiple_files"]))
		{
			//TODO
		}
		else if(isset($_REQUEST["fullpath"]))
		{
			$this->actionDeleteSingleFile( $user, $_REQUEST["fullpath"] );
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return false;
		}
	}

	public function actionDeleteSingleFile( $user, $fullpath )
	{
		$file = $this->getFileInfoByFullpath( $fullpath );

		//else if( isset($_REQUEST["file_id"]) )
		//	$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return false;
		}

		//compute the size to reduce from the quota
		$fullpath = $file->unit_name . "/" . $file->folder . "/" . $file->filename;
		$bytes = $this->getFileSize( $fullpath );
		if($bytes === false)
		{
			$this->result["status"] = -1;
			debug("Filesize of " . $fullpath );
			$this->result["msg"] = "File size cannot be computed";
			//force remove from DB
			$this->deleteFileById( $file->id, $user->id );
			return false;
		}

		if($bytes < $file->size)
			$bytes = $file->size; //just to be sure we adapt the quota right

		//delete the file
		if( !$this->deleteFileById( $file->id, $user->id ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return false;
		}

		$this->changeUnitUsedSize( $file->unit_id, -$bytes, true );
		$unit = $this->getUnit( $file->unit_id );

		$this->result["unit"] = $unit;
		$this->result["status"] = 1;
		$this->result["msg"] = "file deleted";

		return true;
	}

	public function actionMoveFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$file = null;

		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		$fullpath = $file->fullpath;

		if(!isset($_REQUEST["target_fullpath"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		//check privileges in origin file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionMoveFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//check privileges in destination unit
		$targetfile_info = $this->parsePath( $_REQUEST["target_fullpath"] );
		if(!$targetfile_info)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'fullpath contains invalid characters';
			return;			
		}

		$target_unit = $this->getUnitByName( $targetfile_info->unit, $user->id );
		if(!$target_unit || $target_unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionMoveFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//move file (from DB and HD)
		if( !$this->moveFileById( $file->id, $target_unit->id, $targetfile_info->folder, $targetfile_info->filename ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//compute size
		$target_fullpath = $target_unit->name . "/" . $targetfile_info->folder . "/" . $targetfile_info->filename;
		$filesize = $this->getFileSize( $target_fullpath );
		if($filesize === false)
		{
			debug("ERROR, cannot find file size: " . $target_fullpath);
			$this->result["status"] = -1;
			$this->result["msg"] = "error moving file";
			return;
		}

		//debug( $unit->id . " - " .  $target_unit->id );

		//change quotas from one unit to another
		if($unit->id != $target_unit->id)
		{
			$this->changeUnitUsedSize( $unit->id, -$filesize, true );
			$this->changeUnitUsedSize( $target_unit->id, $filesize, true );
			$unit->used_size -= $filesize;
			$target_unit->used_size += $filesize;
		}

		//in case it has preview
		$this->renamePreview( $fullpath, $target_fullpath );

		$this->result["status"] = 1;
		$this->result["msg"] = "file moved";
		$this->result["unit"] = $unit;
		$this->result["target_unit"] = $target_unit;
		$this->result["last_error"] = $this->last_error;
	}

	public function actionCopyFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$file = null;

		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		$fullpath = $file->fullpath;

		if(!isset($_REQUEST["target_fullpath"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		//check privileges in destination unit
		$targetfile_info = $this->parsePath( $_REQUEST["target_fullpath"] );
		if(!$targetfile_info)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'fullpath contains invalid characters';
			return;			
		}

		$target_unit = $this->getUnitByName( $targetfile_info->unit, $user->id );
		if(!$target_unit || $target_unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionCopyFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}
		$target_fullpath = $target_unit->name . "/" . $targetfile_info->folder . "/" . $targetfile_info->filename;
		debug("Target path will be : " . $target_fullpath );

		//copy file (from DB and HD)
		$origin_filesize = $this->getFileSize( $fullpath );
		$target_filesize = $this->getFileSize( $target_fullpath );
		if(!$target_filesize)
			$target_filesize = 0;
		$diff_size = $origin_filesize - $target_filesize;
		debug("Diff size: " . $diff_size );

		if( !$this->copyFileById( $file->id, $target_unit->id, $targetfile_info->folder, $targetfile_info->filename, $user->id ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//compute size
		$filesize = $this->getFileSize( $target_fullpath );
		if($filesize === false)
		{
			debug("ERROR, cannot find file size: " . $target_fullpath);
			$this->result["status"] = -1;
			$this->result["msg"] = "error moving file";
			return;
		}

		//debug( $unit->id . " - " .  $target_unit->id );

		//change quotas from one unit to another
		$this->changeUnitUsedSize( $target_unit->id, $diff, true );

		//in case it has preview
		$this->copyPreview( $fullpath, $target_fullpath );

		$this->result["status"] = 1;
		$this->result["msg"] = "file copied";
		$this->result["unit"] = $unit;
		$this->result["target_unit"] = $target_unit;
		$this->result["last_error"] = $this->last_error;
	}

	public function actionUpdateFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		//which file
		$file = null;
		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		$fullpath = $file->fullpath;

		//check privileges in file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUpdateFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		// FILE DATA RETRIEVING ***************
		$encoding = "text";
		if( isset($_REQUEST["encoding"]) && $_REQUEST["encoding"] != "")
			$encoding = $_REQUEST["encoding"];
		if( $encoding == "file" )
		{
			$data = $this->readFileData("data");
			if ($data === false )
				return;
		}
		else if(!isset($_REQUEST["data"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'data params missing';
			return;
		}
		else
			$data = $_REQUEST["data"];
		if($encoding == "base64")
			$data = base64_decode($data);
		//***************


		//compute difference in bytes
		$bytes = strlen($data);
		$old_size = $this->getFileSize($fullpath);
		$diff = $bytes - $old_size;
		if( ($unit->usedsize + $diff) > $unit->totalsize)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "not enough free space";
			return;
		}

		//update metadata
		if(isset($_REQUEST["metadata"]) && (!isset($info->metadata) || (isset($info->metadata) && $_REQUEST["metadata"] != $info->metadata)))
		{
			if( !$this->updateFileInfo($file->id, Array( "metadata" => $_REQUEST["metadata"] ) ) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = $this->last_error;
				return;
			}
		}

		//update file content
		if( !$this->updateFile($file->id, $data) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//update used size
		$this->changeUnitUsedSize( $unit->id, $diff, true );

		//update preview if necessary
		if( isset($_REQUEST["preview"]) )
		{
			if(!$this->updateFilePreview( $file->id, $_REQUEST["preview"] ) )
			{
				$this->result["msg"] = "Error updating preview: " . $this->last_error;
				return;
			}
		}

		$unit->used_size += $diff;

		$this->result["status"] = 1;
		$this->result["unit"] = $unit;
		$this->result["msg"] = "file updated";
	}

	public function actionUpdateFilePart()
	{
		$user = $this->getUserByToken();
		if( !$user ) //result already filled in getTokenUser
			return;

		//which file
		$file = null;
		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file params missing";
			return;
		}

		if(!isset($_REQUEST["offset"]) || !isset($_REQUEST["total_size"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "part params missing";
			return;
		}


		if( !$file )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		$fullpath = $file->fullpath;

		//check privileges in file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUpdateFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		debug( $fullpath  );
		$old_size = $this->getFileSize( $fullpath );

		// FILE DATA RETRIEVING ***************
		$encoding = "text";
		if( isset($_REQUEST["encoding"]) && $_REQUEST["encoding"] != "")
			$encoding = $_REQUEST["encoding"];
		if( $encoding == "file" )
		{
			$data = $this->readFileData("data");
			if ($data === false )
				return;
		}
		else if(!isset($_REQUEST["data"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'data params missing';
			return;
		}
		else
			$data = $_REQUEST["data"];
		if($encoding == "base64")
			$data = base64_decode($data);
		//***************


		//check the file is big enough
		$offset = intval($_REQUEST["offset"]);
		$total_size = intval($_REQUEST["total_size"]);
		if( $old_size != $total_size)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "sizes do not match: " . $old_size . " != " . $total_size;
			return;
		}

		//update file content
		if( !$this->updateFilePart( $file->id, $data, $offset ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		$this->result["status"] = 1;
		$this->result["unit"] = $unit;
		$this->result["size"] = $this->getFileSize( $fullpath );
		$this->result["msg"] = "file part updated";
	}

	public function actionUpdateFilePreview()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		//which file
		$file = null;
		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		if(!isset($_REQUEST["preview"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		$fullpath = $file->fullpath;

		//check privileges in file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUpdateFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}


		if( !$this->updateFilePreview( $file->id, $_REQUEST["preview"]) )
		{
			debug("Error in updateFilePreview");
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = "preview updated";
	}

	public function actionUpdateFileInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["fullpath"]) || !isset($_REQUEST["info"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		$info = json_decode( $_REQUEST["info"], true );

		if( !$this->updateFileInfo( $file->id, $info, $user->id ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = "info updated";
	}

	/*
	public function actionRestart()
	{
		$code = null;
		if(isset($_REQUEST["restart_code"]))
			$code = $_REQUEST["restart_code"];

		if($code != self::$RESTART_CODE)
		{
			$this->result["msg"] = "I can't let you do that, Dave ";
			return;
		}

		$this->restart();
	}
	*/

	public function getUserByToken()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "no session token";
			return null;
		}

		//check token
		$login = getModule("user");
		$user = $login->checkToken( $_REQUEST["token"] );

		if(!$user)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "token not valid";
			return null;
		}

		return $user;
	}
	

	//**** EVENTS **********************************************
	/*
	public function onCustomUserFields($fields)
	{
	}
	*/

	public function onUserCreated( $user )
	{
		debug("Creating unit for user: " . $user->id);
		$unit = $this->createUnit( $user->id, $user->username, -1, $user->username . " unit" , true );
		if(!$unit || !$unit->id )
		{
			debug("Error, unit not created","red");
			return false;
		}
		else
			debug("unit created: " . $unit->id);

		debug("Creating demo tree");

		//$this->storeFile($user->id, $unit->id, "", "root.txt", "this is an example file", "TEXT", "" );
		//$this->storeFile($user->id, $unit->id, "/test", "test.txt", "this is an example file", "TEXT", "" );
		//$this->storeFile($user->id, $unit->id, "/test/subfolder", "subtest.txt", "this is an example file", "TEXT", "" );
		self::createFolder( $unit->name . "/projects" );

		//allow public folder
		if($user->id > 1) //everybody can read the public folder
			$this->setPrivileges(0, $user->id, "READ");
	}

	public function onUserDeleted( $user )
	{
		$units = $this->getUserUnits( $user->id, true );

		//delete all his files?
		//no, user can leave the system leaving files with no author, they belong to the unit

		//delete every unit
		foreach($units as $i => $unit )
		{
			if( $this->deleteUnit( $unit->id ) )
				debug("Unit deleted: " . $unit->id);
		}

		//delete from privileges
		$database = getSQLDB();
		$query = "DELETE FROM `".DB_PREFIX."privileges` WHERE `user_id` = ". intval($user->id) ;
		$result = $database->query( $query );
		return true;
	}

	public function onSystemInfo( &$info )
	{
		$max_upload = (int)(ini_get('upload_max_filesize'));
		$max_post = (int)(ini_get('post_max_size'));
		$memory_limit = (int)(ini_get('memory_limit'));
		$upload_mb = min($max_upload, $max_post, $memory_limit);

		$info["upload_max_filesize"] = $max_upload;
		$info["post_max_size"] = $max_post;
		$info["memory_limit"] = $memory_limit;
		$info["allow_big_files"] = ALLOW_BIG_FILES;

		$info["max_filesize"] = $upload_mb * 1024*1024;
		$info["max_units"] = self::$MAX_UNITS_PER_USER;
		$info["unit_max_size"] = self::$UNIT_MAX_SIZE;
		$info["unit_min_size"] = self::$UNIT_MIN_SIZE;
		if( USE_EXTENSIONS_WHITELIST )
			$info["whitelist"] = EXTENSIONS_WHITELIST;
		if( USE_EXTENSIONS_BLACKLIST )
			$info["blacklist"] = EXTENSIONS_BLACKLIST;
		$info["files_path"] = substr( FILES_PATH, -1 ) == "/" ? FILES_PATH : FILES_PATH . "/"; //ensure the last character is a slash
		$info["preview_prefix"] = PREVIEW_PREFIX;
		$info["preview_sufix"] = PREVIEW_SUFIX;
		$info["preview_max_filesize"] = self::$MAX_PREVIEW_FILE_SIZE;
		$info["server_free_space"] = disk_free_space(".");
	}

	//remove extra slashes
	public function clearPathName( $name )
	{
		$folders = explode("/",$name);
		$folders = array_filter( $folders );
		return implode("/",$folders);
	}

	public function parsePath( $fullpath, $is_folder = false )
	{
		$fullpath = $this->clearPathName($fullpath);

		//check for invalid characters
		if(preg_match('/^[0-9a-zA-Z\/\_\- ... ]+$/', $fullpath) == FALSE) {
			return null;
		}

		$pos = strpos( $fullpath, "?");
		if($pos != FALSE) //remove trailings url stuff
			$fullpath = substr(0, $pos);
	
		$t = explode( "/", $fullpath );

		$info = new stdClass();
		$info->unit = array_shift($t);
		if(!$is_folder)
			$info->filename = $this->clearPathName( array_pop($t) );
		$info->folder = $this->clearPathName( implode( "/", $t ) );
		$info->fullpath = $info->unit . "/" . $info->folder;
		if(!$is_folder)
			$info->fullpath .= ( $info->folder == "" ? "" : "/" ) . $info->filename;
		if($info->folder == "/")
			$info->folder = "";
		$info->fullpath = $this->clearPathName( $info->fullpath );
		return $info;
	}

	public function validateFilename( $filename )
	{
		return preg_match('/^[0-9a-zA-Z\_\- ... ]+$/', $filename);
	}

	public function validateFolder( $folder )
	{
		if( strlen($folder) == 0 )
			return true; //we accept an empty string as folder name (for the root folder)
		return preg_match('/^[0-9a-zA-Z\/\_\- ... ]+$/', $folder);
	}

	/*
	public function validateFilename( $filename )
	{
		//this could be improved by a regular expression...
		$forbidden = str_split("/|,$:;");

		if( strpos($filename,"..") != FALSE )
			return false;

		foreach($forbidden as $i => $c)
		{
			if( strpos($filename, $c) != FALSE  )
				return false;
		}
		return true;
	}
	*/

	public function validateExtension( $filename )
	{
		$extension = pathinfo($filename, PATHINFO_EXTENSION);

		if( USE_EXTENSIONS_WHITELIST && strpos( EXTENSIONS_WHITELIST, $extension) === false )
			return false;

		if( USE_EXTENSIONS_BLACKLIST && strpos( EXTENSIONS_BLACKLIST, $extension) !== false )
			return false;

		return true;
	}

	//**************************************************
	public function createUnit( $user_id, $unit_name, $size, $desc_name = "", $change_user_quota = false )
	{
		if($size == -1)
			$size = self::$DEFAULT_UNIT_SIZE;

		if($size == 0)
		{
			debug("ERROR: unit size is 0");
			return false;
		}

		if( defined("UNITNAME_SALT") )
			$salt = UNITNAME_SALT;
		else
			$salt = "silly salt"; //for legacy installations

		//generate random unit name
		if(!$unit_name)
			$unit_name = md5( $salt . $user_id . time() . rand() ); //has to be different from invite to avoid people autoinviting

		$invite_token = md5( "invite" . $salt . $user_id . time() . rand() );

		//check if there is already a unit with that name
		$query = "SELECT * FROM `".DB_PREFIX."units` WHERE `name` = '" . addslashes($unit_name) . "'";
		$database = getSQLDB();
		$result = $database->query( $query );
		if ($result === false || $result->num_rows != 0)
		{
			if($result === false)
				debug("error in SELECT when checking if unit exist");
			else
				debug("unit already exists");
			return null;
		}

		if($desc_name == "")
			$desc_name = $unit_name;
		$metadata = "{\"name\":\"".addslashes($desc_name)."\"}";

		//insert DB entry
		$query = "INSERT INTO `".DB_PREFIX."units` (`id` , `name` , `invite_token` , `author_id` , `metadata` , `used_size`, `total_size` ) VALUES ( NULL ,'".$unit_name ."','".$invite_token."',".intval($user_id).",'".$metadata."',0,".intval($size).")";

		$database = getSQLDB();
		$result = $database->query( $query );
		if(!$result)
			return null;

		$unit_id = -1;
		if ($database->insert_id != 0)
			$unit_id = $database->insert_id;

		if ($unit_id == -1)
		{
			debug("error inserting in the db a unit");
			$this->last_error = "DB PROBLEM";
			return null;
		}

		//create folder
		$this->createFolder( $unit_name );

		//give privileges to user
		if( !$this->setPrivileges( $unit_id, $user_id, "ADMIN" ) )
		{
			debug("Problem setting privileges");
			return null;
		}

		if($change_user_quota)
			getModule("user")->changeUserUsedSpace( $user_id, $size, true );

		$unit = $this->getUnit($unit_id);
		return $unit;
	}

	public function deleteUnit($id, $change_user_quota = false)
	{
		global $database;
		$id = intval($id);

		$unit = $this->getUnit( $id );
		if(!$unit)
		{
			debug("Unit with wrong id");
			return false;
		}

		//delete from units DB
		$query = "DELETE FROM `".DB_PREFIX."units` WHERE `id` = " . $id . " LIMIT 1";
		$database = getSQLDB();
		$result = $database->query( $query );
		if(!$result)
		{
			debug("error deleting");
			return false;
		}
		if($database->affected_rows == 0)
		{
			debug("weird deleting");
			return false;
		}

		//delete files from DB
		$query = "DELETE FROM `".DB_PREFIX."files` WHERE `unit_id` = " . $id;
		$database = getSQLDB();
		$result = $database->query( $query );
		if(!$result)
		{
			debug("error deleting");
			return false;
		}

		//delete files from HD
		$this->delTree( $unit->name );

		//delete from privileges
		$query = "DELETE FROM `".DB_PREFIX."privileges` WHERE `unit_id` = ".$id;
		$result = $database->query( $query );

		//delete comments
		//TODO

		//change user quota
		if( $change_user_quota )
		{
			$usermodule = getModule("user");
			if( !$usermodule->changeUserUsedSpace( $unit->author_id, -$unit->total_size, true) )
			{
				debug('problem changing user used space');
				return false;
			}
			debug("user used space changed");
		}

		return true;
	}

	public function setUnitMetadata($id, $data)
	{
		global $database;

		$id = intval($id);
		$data = addslashes($data);

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."units` SET `metadata` = '".$data."' WHERE `id` = ".$id.";";
		$result = $database->query( $query );
		if(!$result)
			return false;
		if($database->affected_rows == 0)
			return false;
		return true;
	}

	//is delta means if the $size number is the result size or you want to add it to the current size
	public function changeUnitUsedSize($id, $size, $is_delta = false)
	{
		global $database;
		if(!is_numeric($size))
			return false;

		$id = intval($id);
		$size = intval($size);

		$database = getSQLDB();
		if($is_delta)
			$query = "UPDATE `".DB_PREFIX."units` SET `used_size` = `used_size` + ".intval($size)." WHERE `id` = ".$id.";";
		else
			$query = "UPDATE `".DB_PREFIX."units` SET `used_size` = ".intval($size)." WHERE `id` = ".$id.";";
		//debug($query);
		$result = $database->query( $query );
		if(!$result)
			return false;
		if($database->affected_rows == 0)
			return false;

		return true;
	}

	public function changeUnitTotalSize($id, $total_size)
	{
		global $database;

		if(!is_numeric($total_size))
			return false;

		$id = intval($id);
		$total_size = intval($total_size);

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."units` SET `total_size` = ".intval($total_size)." WHERE `id` = ".$id.";";

		$result = $database->query( $query );
		if($database->affected_rows == 0)
			return false;
		return true;
	}

	public function setPrivileges($unit_id, $user_id, $mode = NULL)
	{
		$database = getSQLDB();

		//remove
		if($mode == NULL)
		{
			$query = "DELETE FROM `".DB_PREFIX."privileges` WHERE `unit_id` = ".intval($unit_id)." AND `user_id` = " . intval($user_id);
			$result = $database->query( $query );
			return true;
		}

		//check privileges
		$query = "SELECT * FROM `".DB_PREFIX."privileges` WHERE `unit_id` = ".intval($unit_id)." AND `user_id` = " . intval($user_id);
		$result = $database->query( $query );

		//no privileges found for this user in this unit
		if ($result === false || $result->num_rows == 0)
		{
			//CREATE
			$query = "INSERT INTO `".DB_PREFIX."privileges` (`id` , `unit_id` , `user_id` , `mode` ) VALUES ( NULL ,".intval($unit_id) .",".intval($user_id).",'".$mode."')";
			$result = $database->query( $query );

			$id = -1;
			if ($database->insert_id != 0)
				$id = $database->insert_id;
			if ($id == -1)
			{
				debug("error inserting privileges in the db");
				$this->last_error = "DB PROBLEM";
				return false;
			}
			return true;
		}

		//UPDATE: get privileges info
		$item = $result->fetch_object();

		//no changes
		if($item->mode == $mode)
			return true;

		$query = "UPDATE `".DB_PREFIX."privileges` SET `mode` = '".$mode."' WHERE `id` = ".$item->id." LIMIT 1";
		$result = $database->query( $query );
		if($database->affected_rows == 0)
			return false;

		return true;
	}

	//clean
	public function processUnit( $unit ) 
	{
		if(!$unit)
			return null;
		$unit->id = intval($unit->id);
		$unit->author_id = intval($unit->author_id);
		$unit->total_size = intval($unit->total_size);
		$unit->used_size = intval($unit->used_size);
		$unit->metadata = stripslashes( $unit->metadata );
		return $unit;
	}

	//return units where the user has privileges or is the owner
	public function getUserUnits( $user_id, $owner = false )
	{
		//check privileges
		$database = getSQLDB();

		if($owner)
			$query = "SELECT * FROM `".DB_PREFIX."units` WHERE `author_id` = " . intval($user_id);
		else
			$query = "SELECT * FROM `".DB_PREFIX."privileges` as privileges, `".DB_PREFIX."units` AS units  WHERE `user_id` = " . intval($user_id) . " AND units.id = privileges.unit_id";

		$result = $database->query( $query );
		$units = Array();
		while($unit = $result->fetch_object())
			$units[] = $this->processUnit($unit);
		return $units;
	}

	public function getUnit( $unit_id, $user_id = -1 )
	{
		$database = getSQLDB();
		if($user_id != -1)
		{
			//check privileges
			$query = "SELECT * FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."privileges` as privileges WHERE units.id = " . intval($unit_id) . " AND units.id = privileges.unit_id AND privileges.user_id = " . intval($user_id);
		}
		else
			$query = "SELECT * FROM `".DB_PREFIX."units` WHERE `id` = " . intval($unit_id);

		$result = $database->query( $query );
		if(!$result)
			return null;

		return $this->processUnit( $result->fetch_object() );
	}

	public function getUnitByName( $name, $user_id = -1 )
	{
		$database = getSQLDB();
		$user_id = intval($user_id);
		$name = addslashes($name);

		//check privileges
		if($user_id != -1)
		{
			$query = "SELECT units.*, privileges.mode, users.username AS author FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."users` AS users, `".DB_PREFIX."privileges` as privileges WHERE units.name = '" . $name . "' AND units.id = privileges.unit_id AND privileges.user_id = " . $user_id . " AND units.author_id = users.id";
		}
		else
			$query = "SELECT units.*, users.username AS author FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."users` AS users WHERE `name` = '" . $name . "' AND units.author_id = users.id";

		$result = $database->query( $query );
		if(!$result)
			return null;

		$unit = $this->processUnit( $result->fetch_object() );
		return $unit;
	}

	public function getUnitByToken( $token, $user_id = -1 )
	{
		$database = getSQLDB();
		$user_id = intval($user_id);
		$token = addslashes($token);

		//check privileges
		if($user_id != -1)
		{
			$query = "SELECT units.*, privileges.mode, users.username AS author FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."users` AS users, `".DB_PREFIX."privileges` as privileges WHERE units.invite_token = '" . $token . "' AND units.id = privileges.unit_id AND privileges.user_id = " . $user_id . " AND units.author_id = users.id";
		}
		else
			$query = "SELECT units.*, users.username AS author FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."users` AS users WHERE `invite_token` = '" . $token . "' AND units.author_id = users.id";

		$result = $database->query( $query );
		if(!$result)
			return null;

		$unit = $this->processUnit( $result->fetch_object() );
		return $unit;
	}



	public function getUnitUsers( $unit_id )
	{
		$database = getSQLDB();

		$query = "SELECT users.username, privileges.timestamp, privileges.mode FROM `".DB_PREFIX."privileges` AS privileges, `".DB_PREFIX."users` AS users WHERE `unit_id` = " . intval($unit_id) ." AND users.id = privileges.user_id";
		//debug($query);
		$result = $database->query( $query );
		if(!$result)
			return null;
		$users = Array();
		while($user = $result->fetch_object())
			$users[] = $user;
		return $users;
	}


	//Doesnt check privileges or modifies unit size
	public function storeFile( $user_id, $unit_id, $folder, $filename, $fileData, $category, $metadata, $totalSize = 0 )
	{
		$database = getSQLDB();

		$user_id = intval($user_id);
		$unit_id = intval($unit_id);
		$filename = addslashes( trim($filename) );
		if ( strlen($folder) > 0 && $folder[0] == "/" ) 
			$folder = substr($folder, 1);
		$folder = addslashes( trim($folder) );
		$category = addslashes($category);
		$metadata = addslashes($metadata);

		if( $fileData == NULL )
			$size = intval( $totalSize );
		else
			$size = strlen( $fileData );

		//SAFETY FIRST
		if(!$this->validateFilename( $filename) || strpos($folder,"..") != FALSE)
		{
			debug("Filename contains invalid characters");
			$this->last_error = "Invalid filename";
			return null;
		}

		if( !$this->validateExtension( $filename ) )
		{
			debug("invalid extension: " . $filename);
			$this->last_error = "Extension not allowed: " . $filename;
			return null;
		}

		//clear
		if($folder == "/")
			$folder = "";
		$folder = $this->clearPathName( $folder );
		$filename = $this->clearPathName( $filename );

		//unit
		$unit = $this->getUnit( $unit_id ); // $user_id) //this functions doesnt control privileges
		if(!$unit)
		{
			debug("ERROR: Unit not found: " . $unit_id);
			return null;
		}

		//final filename
		$fullpath = $unit->name ."/" . $folder . "/" . $filename;

		//already exists?
		$exist = $this->fileExist( $fullpath );
		if($exist)
		{

			$file_info = $this->getFileInfoByFullpath($fullpath);
			if(!$file_info)
			{
				debug("something weird happened");
				$this->last_error = "ERROR: file found but no SQL entry";
				return null;
			}

			if($file_info->author_id != $user_id )
			{
				$unit = $this->getUnit( $unit_id, $user_id ); 
				if( $unit->mode != "ADMIN") //WARNING!!! WHAT ABOUT THE QUOTA, IT WILL BE APPLYED TO HIM INSTEAD OF THE AUTHOR
				{
					debug("user removing file that doesnt belongs to him");
					$this->last_error = "File belongs to other user";
					return null;
				}
			}		
			$id = $file_info->id;	
			debug("file found with the same name, overwriting");

			//update size
			if( $size != intval( $file_info->size ) )
			{
				$query = "UPDATE `".DB_PREFIX."files` SET `size` = ".$size." WHERE `id` = ".$id." LIMIT 1";
				$result = $database->query( $query );
				if($database->affected_rows == 0)
					debug("SIZE not changed");
			}
		}
		else //file dont exist
		{
			//insert DB entry
			$query = "INSERT INTO `".DB_PREFIX."files` (`id` , `filename` , `category` , `unit_id`, `folder` , `metadata` , `author_id` , `size`) VALUES ( NULL ,'".$filename ."','".$category."',".$unit_id.",'" . $folder. "', '".$metadata."', ".$user_id ." , ".$size." )";
			
			$result = $database->query( $query );

			$id = -1;
			if ($database->insert_id != 0)
				$id = $database->insert_id;
			if ($id == -1)
			{
				debug("error inserting in the db");
				$this->last_error = "DB PROBLEM";
				return null;
			}

			if(!$this->folderExist( $unit->name . "/" . $folder)) 
			{
				$this->createFolder( $unit->name . "/" . $folder );
				if( !$this->folderExist( $unit->name . "/" . $folder ) )
				{
					debug("wrong folder name");
					$this->last_error = "Error in Folder name";
					return null;
				}
			}
		}

		$created = false;

		//create empty file
		if( $fileData == NULL || $size == 0 )
		{
			debug("Creating empty file");
			$created = self::createEmptyFile( $fullpath, $size );
		}
		else //save file in hard drive
			$created = self::writeFile( $fullpath, $fileData );

		if( $created == false )
		{
			debug( "file size is 0 after trying to write it to HD: " . $fullpath );
			$this->last_error = "PROBLEM WRITTING FILE";
			$query = "DELETE FROM `".DB_PREFIX."files` WHERE 'id' = " . $id;
			$result = $database->query( $query );
			if(!isset($database->affected_rows) || $database->affected_rows == 0)
			{
				debug("couldnt remove the file entry from the DB after writing the file to HD failed. ?!?!");
				return null;
			}
			//TODO: recover unit size
			return null;
		}

		return $id;
	}

	public function updateFile( $file_id, $fileData )
	{
		$file_id = intval($file_id);

		$file_info = $this->getFileInfoById($file_id);
		if( !$file_info )
		{
			$this->last_error = "WRONG ID";
			return false;
		}

		$fullpath = $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename;

		//already exists?
		if(!self::fileExist($fullpath))
		{
			$this->last_error = "FILE NOT FOUND IN HD: " . $fullpath;
			return false;
		}

		//self::deleteFile($fullpath); //do not need to remove

		//save file in hard drive
		if( !self::writeFile( $fullpath, $fileData ) )
		{
			debug("file couldnt be written");
			$this->last_error = "PROBLEM WRITTING FILE";
			return false;
		}

		return true;
	}

	public function updateFilePart( $file_id, $fileData, $offset )
	{
		$file_id = intval( $file_id );

		$file_info = $this->getFileInfoById( $file_id );
		if( !$file_info )
		{
			$this->last_error = "WRONG ID";
			return false;
		}

		if( $file_info->size < ( strlen( $fileData ) + intval($offset)) )
		{
			$this->last_error = "FILE PART EXCEEDS CURRENT FILE SIZE";
			return false;
		}

		$fullpath = $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename;

		//already exists?
		if(!self::fileExist($fullpath))
		{
			$this->last_error = "FILE NOT FOUND IN HD: " . $fullpath;
			return false;
		}

		if( !strlen($fileData) )
		{
			$this->last_error = "FILE PART EMPTY";
			return false;
		}

		//save file in hard drive
		if( !self::writeFilePart( $fullpath, $fileData, $offset ) )
		{
			debug("file couldnt be written");
			$this->last_error = "PROBLEM WRITTING FILE";
			return false;
		}

		return true;
	}


	public function downloadFolder( $unit, $folder )
	{
		if( strpos($folder, "..") != FALSE )
			return null;

		$temp_folder = __DIR__ . "/../../tmp";

		if(!is_dir( $temp_folder ))
		{
			mkdir( $temp_folder );  
			chmod( $temp_folder, 0775);
		}

		$fullpath = $unit->name . "/" . $folder;

		$output = array();

		//purge old files (DANGEROUS)
		debug("Purguing old files...");
		$cmd = "find ". $temp_folder ." -mtime +1 -exec rm {} \;";
		exec( $cmd, $output );
		debug( $output );

		//creating temporary tar.gz file with the folder content
		$tmp_filename = $temp_folder . "/folder_download_" . ((string)time()) . "_" . ((string)rand()) . ".tar.gz";
		debug("Compressing FILES (this could take some time)...");
		$cmd = "tar -cvzf ".$tmp_filename." ". FILES_PATH . $fullpath . "/ 2>&1";
		debug( $cmd );
		exec( $cmd, $output );
		debug( $output );

		$url = "tmp/" . basename( $tmp_filename );
		debug("File at: ".$tmp_filename);
		return $url;
	}

	public function deleteFolder( $unit, $folder )
	{
		if( strpos($folder, "..") != FALSE )
			return false;

		$folder = addslashes($folder);

		$usedsize = 0;

		//delete previews
		$files = $this->getFilesFromDB( $unit->id, $folder, true );
		foreach( $files as $i => $file )
		{
			$fullpath = $unit->name . "/" . $file->folder . "/" . $file->filename;
			$usedsize += $file->size;
			$this->deletePreview($fullpath);
		}

		//remove files from DB
		$database = getSQLDB();
		$query = "DELETE FROM `".DB_PREFIX."files` WHERE (`folder` = '". $folder . "' OR `folder` = '". $folder . "/%') AND `unit_id` = " . intval($unit->id);
		$result = $database->query( $query );

		$this->changeUnitUsedSize( $unit->id, -$usedsize, true );

		//delete folder
		$this->delTree( $unit->name . "/" . $folder );
		return true;
	}

	public function moveFolder( $origin, $target )
	{
		if( strpos($origin, "..") != FALSE || strpos($target, "..") != FALSE )
			return false;

		/* TODO
		$origin = addslashes($origin);

		$usedsize = 0;

		//delete previews
		$files = $this->getFilesFromDB( $unit->id, $origin, true );
		foreach( $files as $i => $file )
		{
			$fullpath = $unit->name . "/" . $file->folder . "/" . $file->filename;
			$usedsize += $file->size;
			$this->deletePreview($fullpath);
		}

		//remove files from DB
		$database = getSQLDB();
		$query = "DELETE FROM `".DB_PREFIX."files` WHERE (`folder` = '". $folder . "' OR `folder` = '". $folder . "/%') AND `unit_id` = " . intval($unit->id);
		$result = $database->query( $query );

		$this->changeUnitUsedSize( $unit->id, -$usedsize, true );

		//delete folder
		$this->delTree( $unit->name . "/" . $folder );
		*/
		return true;
	}

	//PREVIEW ***********************************************

	public function updateFilePreview( $file_id, $fileData )
	{
		if(strlen($fileData) > self::$MAX_PREVIEW_FILE_SIZE)
		{
			debug("preview size exceeds limit");
			return false;
		}

		$file_id = intval($file_id);

		$file_info = $this->getFileInfoById($file_id);
		if($file_info == null)
			return false;

		$fileData = substr($fileData, strpos($fileData, ",")+1); //remove encoding info data
		$fileData = base64_decode($fileData);

		$tn_filename = PREVIEW_PREFIX . $file_info->filename . PREVIEW_SUFIX;
		$tn_path = $file_info->unit_name . "/" . $file_info->folder . "/" . $tn_filename;

		if( !self::writeFile( $tn_path, $fileData ) )
		{
			debug("problem writing preview file");
			return false;
		}

		return true;
	}

	public function generateFilePreview( $file_id )
	{
		debug("generating preview");

		$file_id = intval($file_id);
		$file_info = $this->getFileInfoById($file_id);
		if($file_info == null)
		{
			debug("cannot generate preview, file_id not found");
			return false;
		}

		//direct path
		$realpath = realpath( self::getFilesFolderName() . "/" . $file_info->fullpath );
		debug("realpath: " . $realpath);
		$info = pathinfo( $realpath ); //to extract extension

		$previewWidth =  self::$PREVIEW_IMAGE_SIZE;

		$ext = strtolower($info['extension']);
		$img = null;

		switch($ext)
		{
			case 'jpeg':
			case 'jpg': $img = imagecreatefromjpeg( $realpath ); break;
			case 'png':	$img = imagecreatefrompng( $realpath ); break;
			case 'webp': $img = imagecreatefromwebp( $realpath ); break;
			default:
				debug("cannot preview, unknown extension: " . $ext );
				return false;
		}

		if(!$img)
		{
			debug("Image couldnt be loaded: " . $realpath );
			return false;
		}

		$width = imagesx( $img );
		$height = imagesy( $img );

		// calculate preview size
		$new_width = $previewWidth;
		$new_height = floor( $height * ( $previewWidth / $width ) );

		// create a new temporary image
		$tmp_img = imagecreatetruecolor( $new_width, $new_height );

		// copy and resize old image into new image 
		imagecopyresized( $tmp_img, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height );

		$picpath = $file_info->unit_name . "/" . $file_info->folder;
		$tn_filename = PREVIEW_PREFIX . $file_info->filename . PREVIEW_SUFIX;

		$tn_path = $this->getFilesFolderName() . "/" . $picpath . "/" . $tn_filename ;

		// save preview into a file
		$result = null;
		if(PREVIEW_SUFIX == ".png")
			$result = imagepng( $tmp_img, $tn_path );
		else if(PREVIEW_SUFIX == ".jpg")
			$result = imagejpeg( $tmp_img, $tn_path );
		else if(PREVIEW_SUFIX == ".webp")
			$result = imagewebp( $tmp_img, $tn_path );
		else
		{
			debug("unsupported preview extension");
			return false;
		}

		if( !$result )
		{
			debug("Error saving generated preview: " . $tn_path );
			return false;
		}

		debug("preview generated: " . $tn_path );
		return true;
	}

	public function renamePreview($old_fullpath, $new_fullpath )
	{
		if($old_fullpath == $new_fullpath)
			return true;

		$old_path_info = $this->parsePath( $old_fullpath );
		$new_path_info = $this->parsePath( $new_fullpath );

		$old_filename = $old_path_info->unit . "/" . $old_path_info->folder . "/" . PREVIEW_PREFIX . $old_path_info->filename . PREVIEW_SUFIX;
		$new_filename = $new_path_info->unit . "/" . $new_path_info->folder . "/" . PREVIEW_PREFIX . $new_path_info->filename . PREVIEW_SUFIX;

		//no preview
		if(!self::fileExist( $old_filename ) )
		{
			//debug( "'" . $old_path_info->folder . "''" . $old_path_info->filename ."'");
			//debug("no preview found: " . $old_filename);
			return false;
		}

		$result = self::moveFile( $old_filename, $new_filename );
		if(!$result)
			debug("Problem moving preview");
		return $result;
	}

	public function copyPreview($old_fullpath, $new_fullpath )
	{
		if($old_fullpath == $new_fullpath)
			return true;

		$old_path_info = $this->parsePath( $old_fullpath );
		$new_path_info = $this->parsePath( $new_fullpath );

		$old_filename = $old_path_info->unit . "/" . $old_path_info->folder . "/" . PREVIEW_PREFIX . $old_path_info->filename . PREVIEW_SUFIX;
		$new_filename = $new_path_info->unit . "/" . $new_path_info->folder . "/" . PREVIEW_PREFIX . $new_path_info->filename . PREVIEW_SUFIX;

		//no preview
		if(!self::fileExist( $old_filename ) )
		{
			//debug( "'" . $old_path_info->folder . "''" . $old_path_info->filename ."'");
			//debug("no preview found: " . $old_filename);
			return false;
		}

		$result = self::copyFile( $old_filename, $new_filename );
		if(!$result)
			debug("Problem copying preview");
		return $result;
	}

	public function deletePreview($fullpath)
	{
		$path_info = $this->parsePath( $fullpath, true );

		$filename = $path_info->unit . "/" . $path_info->folder . "/" . PREVIEW_PREFIX . $path_info->filename . PREVIEW_SUFIX;

		//no preview
		if(!$this->fileExist( $filename ) )
			return false;

		$result = self::deleteFile( $filename );
		if(!$result)
			debug("Problem deleting preview: " .  $filename );
		return $result;
	}

	// File category and metadata ************************************

	public function readFileData( $field_name = "data" )
	{
		if(!isset($_FILES[ $field_name ]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'file missing';
			return false;
		}

		$file = $_FILES[ $field_name ];
		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'file not found';
			return false;
		}

		$err = "";
		if(isset($file["error"]))
		{
			$errnum = $file["error"];
			switch ($errnum)
			{
				case UPLOAD_ERR_OK:	break;
				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					$max_upload = (int)(ini_get('upload_max_filesize'));
					$max_post = (int)(ini_get('post_max_size'));
					$memory_limit = (int)(ini_get('memory_limit'));
					$upload_mb = min($max_upload, $max_post, $memory_limit);
					$err = 'File too large (limit of '.$upload_mb.' MBs).';
					break;
				case UPLOAD_ERR_PARTIAL:
					$err = 'File upload was not completed.';
					break;
				case UPLOAD_ERR_NO_FILE:
					$err = 'Zero-length file uploaded.';
					break;
				default:
					break;
			}
		}

		if($err != "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'error uploading file';
			$this->result["error"] = $err;
			return false;
		}

		//Read data (TODO: optimize, move file directly)
		$data = file_get_contents( $file["tmp_name"] );
		if($data == false)
		{
			debug( "Filename: " . $file["tmp_name"] );
			$this->result["status"] = -1;
			$this->result["msg"] = 'error reading file data in server, check server logs';
			return false;
		}

		//erase tmp file (I discovered problems due to leaving the tmps files)
		unlink( $file["tmp_name"] );

		return $data;
	}


	public function updateFileInfo($file_id, $info, $user_id = -1 )
	{
		$file_id = intval($file_id);
		$file_info = $this->getFileInfoById($file_id);

		if($file_info == null)
		{
			$this->last_error = "WRONG ID";
			return false;
		}

		$updates = Array();

		if( isset( $info["metadata"] ) ) 
			$updates[] = "`metadata` = '". addslashes( $info["metadata"] )."'";

		if( isset( $info["category"] ) ) 
			$updates[] = "`category` = '". addslashes( $info["category"] )."'";

		if( count( $updates ) == 0 )
			return false;

		$filter = "";
		if($user_id != -1)
			$filter = " AND author_id = " . intval( $user_id ); 

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."files` SET ". implode( $updates, "," ) ." WHERE `id` = ".$file_id." ".$filter." LIMIT 1";
		debug($query);
		$result = $database->query( $query );
		if($database->affected_rows == 0)
		{
			debug("No changes when updating file info");
			$this->last_error = "NO CHANGES";
		}

		return true;
	}

	public function deleteFileById( $file_id, $user_id = -1)
	{
		$database = getSQLDB();

		$file_id = intval($file_id);

		//need file info to know folder and unit
		$file_info = $this->getFileInfoById( $file_id );
		if($file_info == null)
		{
			$this->last_error = "File not found";
			return false;
		}

		$unit_id = $file_info->unit_id;
		$unit = $this->getUnit( $unit_id, $user_id );

		if($unit->mode == "READ" && $user_id != -1)
		{
			$this->last_error = "User only has read privileges, cannot delete";
			return false;
		}

		$filter = "";
		if( $user_id != -1 && $unit->mode != "ADMIN" )
			$filter = " AND author_id = " . intval( $user_id );

		//delete from DB
		$query = "DELETE FROM `".DB_PREFIX."files` WHERE `id` = ". $file_id . " " . $filter;
		$result = $database->query( $query );
		if(!isset($database->affected_rows) || $database->affected_rows == 0)
		{
			$this->last_error = "File dont belong to user";
			return false;
		}

		$fullpath = $file_info->fullpath;

		//delete file
		if( !self::deleteFile( $fullpath ) )
		{
			debug("ERROR DELETING FILE FROM HD");
		}

		//remove pic
		$this->deletePreview( $fullpath );
		return true;
	}

	public function moveFileById( $file_id, $new_unit_id, $new_folder, $new_filename )
	{
		$database = getSQLDB();

		$file_id = intval($file_id);
		$new_unit_id = intval($new_unit_id);
		$new_folder = $this->clearPathName( $new_folder );

		//SAFETY FIRST
		if( !$this->validateFilename( $new_filename) )
		{
			debug("Filename contains invalid characters");
			$this->last_error = "INVALID FILENAME";
			return null;
		}

		if( strpos($new_folder,"..") != FALSE )
		{
			debug("Folder contains invalid characters");
			$this->last_error = "INVALID FILENAME";
			return null;
		}

		//CHECK EXTENSION
		if( !$this->validateExtension( $new_filename ) )
		{
			debug("Extension is invalid");
			$this->last_error = "INVALID FILENAME: " . $new_filename;
			return null;
		}


		$file = $this->getFileInfoById($file_id);
		if($file == null)
		{
			debug("WRONG FILE ID");
			$this->last_error = "WRONG FILE ID";
			return false;
		}

		$target_unit = $this->getUnit( $new_unit_id );
		if(!$target_unit)
		{
			debug("WRONG TARGET UNIT: " . $new_unit_id);
			$this->last_error = "WRONG TARGET UNIT";
			return false;
		}

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."files` SET `unit_id` = ".$new_unit_id.", `folder` = '".addslashes($new_folder)."' , `filename` = '".addslashes($new_filename)."' WHERE `id` = ".$file->id;
		
		$result = $database->query( $query );
		if($database->affected_rows == 0)
		{
			$this->last_error = "NOTHING DONE";
			return false;
		}

		if(!self::folderExist( $target_unit->name . "/" . $file->folder) )
			self::createFolder( $target_unit->name . "/" . $file->folder );

		$oldfilepath = $file->unit_name . "/" . $file->folder . "/" . $file->filename;
		$newfilepath = $target_unit->name . "/" .  $new_folder . "/" . $new_filename;

		if( !self::moveFile($oldfilepath, $newfilepath) )	
		{
			debug("Error Moving HD from " . $oldfilepath . "   to   " . $newfilepath );
			$this->last_error = "Problem moving file";

			//undo DB changes
			$query = "UPDATE `".DB_PREFIX."files` SET `unit_id` = ".$file->unit_id.", `folder` = '".$file->folder."' , `filename` = '".$file->filename."' WHERE `id` = ".$file->id;
			$result = $database->query( $query );
			if($database->affected_rows == 0)
				debug("Error undoing changes in DB" );
			else
				debug("Changes in DB undone" );
			return false;
		}

		return true;
	}

	public function copyFileById( $file_id, $new_unit_id, $new_folder, $new_filename, $user_id = -1 )
	{
		$database = getSQLDB();

		$file_id = intval($file_id);
		$new_unit_id = intval($new_unit_id);
		$new_folder = $this->clearPathName( $new_folder );

		//debug("Folder: " . $new_folder );
		//debug("Filename: " . $new_filename );

		//SAFETY FIRST
		if( !$this->validateFilename( $new_filename) )
		{
			debug("Filename contains invalid characters");
			$this->last_error = "INVALID FILENAME";
			return null;
		}

		if( strpos($new_folder,"..") != FALSE )
		{
			debug("Folder contains invalid characters");
			$this->last_error = "INVALID FILENAME";
			return null;
		}

		//CHECK EXTENSION
		if( !$this->validateExtension( $new_filename ) )
		{
			debug("Extension is invalid");
			$this->last_error = "INVALID FILENAME: " . $new_filename;
			return null;
		}


		$file = $this->getFileInfoById($file_id);
		if($file == null)
		{
			debug("WRONG FILE ID");
			$this->last_error = "WRONG FILE ID";
			return false;
		}

		if($user_id == -1)
			$user_id = $file->author_id;

		$target_unit = $this->getUnit( $new_unit_id );
		if(!$target_unit)
		{
			debug("WRONG TARGET UNIT: " . $new_unit_id);
			$this->last_error = "WRONG TARGET UNIT";
			return false;
		}

		$database = getSQLDB();

		$query = "INSERT INTO `".DB_PREFIX."files` (`id` , `filename` , `category` , `unit_id`, `folder` , `metadata` , `author_id` , `size`) VALUES ( NULL ,'".$new_filename ."','".$file->category."',".$new_unit_id.",'" . $new_folder. "', '".$file->metadata."', ".$user_id ." , ".$file->size." )";
		
		$result = $database->query( $query );
		if($database->affected_rows == 0)
		{
			$this->last_error = "NOTHING DONE";
			return false;
		}

		if(!self::folderExist( $target_unit->name . "/" . $new_folder) )
			self::createFolder( $target_unit->name . "/" . $new_folder );

		$oldfilepath = $file->unit_name . "/" . $file->folder . "/" . $file->filename;
		$newfilepath = $target_unit->name . "/" .  $new_folder . "/" . $new_filename;

		if( !self::copyFile( $oldfilepath, $newfilepath ) )	
		{
			debug("Error Copying HD from " . $oldfilepath . "   to   " . $newfilepath );
			$this->last_error = "Problem copying file";
			return false;
		}

		return true;
	}
	/*

	private function getUserFilesInFolder($user_id, $folder = "")
	{
		if( strpos($folder,"..") != FALSE)
		{
			debug("unsafe folder name");
			return null;
		}
		$userfolder = $this->getUserPath($user_id);
		debug($userfolder . "/" . $folder);
		return $this->getFilesInFolder($userfolder . "/" . $folder);
	}

	*/

	public function isReady()
	{
		$database = getSQLDB();

		$tables = array("files", "comments", "units", "privileges");

		foreach($tables as $i => $table)
		{
			$query = "SHOW TABLES LIKE '".DB_PREFIX . $table ."'";
			$result = $database->query( $query );
			if(!$result || $result->num_rows != 1)
			{
				debug("Table not found " . $table . " : " . $query);
				return -1;
			}
		}

		return 1;
	}

	public function preRestart()
	{
		debug("Removing files folders");
		//remove folders: do it in prestart because login could create
		if( $this->folderExist("") )
			$this->delTree("");
	}

	public function restart()
	{
		debug("Droping old tables");
		$database = getSQLDB();

		$query = "DROP TABLE IF EXISTS ".DB_PREFIX."units, ".DB_PREFIX."privileges, ".DB_PREFIX."files, ".DB_PREFIX."comments;";
		$result = $database->query( $query );

		debug("Creating files tables");
		if(!$this->createTables())
			return;

		//create folders
		$this->createFolder("");

		//create public unit
		$this->createUnit(1, "public", 1000*1024*1024, "Public");

		debug("Files folder created");
	}

	public function createTables()
	{
		$database = getSQLDB();
		$login = getModule("user");

		//UNITS
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."units (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`name` VARCHAR(256) NOT NULL,
			`invite_token` VARCHAR(256),
			`author_id` INT NOT NULL,
			`metadata` TEXT NOT NULL,
			`used_size` INT NOT NULL,
			`total_size` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL)
			ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Units table not created");
			$this->result["msg"] = "Units table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Units table created");

		//PRIVILEGES
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."privileges (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`user_id` INT NOT NULL,
			`unit_id` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL,
			`mode` ENUM('READ','WRITE','ADMIN') NOT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Privileges table not created");
			$this->result["msg"] = "Privileges table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Privileges table created");

		//FILES
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."files (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`unit_id` INT NOT NULL,
			`folder` VARCHAR(256) NOT NULL,
			`filename` VARCHAR(256) NOT NULL,
			`category` VARCHAR(256) NOT NULL,
			`metadata` TEXT NOT NULL,
			`author_id` INT NOT NULL,
			`size` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL,
			`status` ENUM('DRAFT','PRIVATE','PUBLIC','BLOCKED') NOT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Files table not created");
			$this->result["msg"] = "Files table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Files table created");

		//COMMENTS
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."comments (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`file_id` INT UNSIGNED NOT NULL,
			`info` TEXT NOT NULL,
			`author_id` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Comments table not created");
			$this->result["msg"] = "Comments table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Comments table created");

		return true;
	}

	public function upgrade()
	{
		debug("Upgrading files tables");

		if(!$this->createTables())
			return false;

		$database = getSQLDB();

		//EXAMPLE to upgrade when something is missing
		if( !self::checkTableHasColumn( DB_PREFIX."units","invite_token") )
		{
			$query = "ALTER TABLE `".DB_PREFIX."units` ADD `invite_token` VARCHAR(256) NOT NULL AFTER `name`;";
			$result = $database->query( $query );
			debug("column added to units table: invite_token");

			if( defined("UNITNAME_SALT") )
				$salt = UNITNAME_SALT;
			else
				$salt = "silly salt"; //for legacy installations

			//add a default invite token to every existing unit
			$query = "SELECT * FROM `".DB_PREFIX."units`;";
			$result = $database->query( $query );
			while($unit = $result->fetch_object())
			{
				$invite_token = md5( "invite" . $salt . $unit->author_id . time() . rand() );
				$query = "UPDATE `".DB_PREFIX."units` SET `invite_token` = '".$invite_token."' WHERE `id` = ".$unit->id;
				$result2 = $database->query( $query );
				if (!$result2)
					debug("error updating invite token");
			}
			debug("invite tokens generated");
		}

		return true;
	}

	public static function checkTableHasColumn( $table, $column )
	{
		$database = getSQLDB();
		$query = "SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '".DB_NAME."' AND TABLE_NAME = '".$table."' AND COLUMN_NAME = '".$column."'";
		$result = $database->query( $query );	
		return $result->num_rows > 0 ? TRUE : FALSE;
	}

	// SANDBOXED FILE ACTIONS ***********************************************************

	public static function getFilesFolderName() 
	{
		//jump back from includes/modules/
		return __DIR__ . "/../../" . FILES_PATH;
	}

	public function getFolders( $unit_name )
	{
		$folder = self::getFilesFolderName() . "/" . $unit_name . "/";
		$folders = $this->getSubfolders( $folder );
		return $folders;
	}

	//recursive search
	private function getSubfolders( $dir )
	{
		$folders = Array();
		error_reporting(0);
		$files = array_diff( scandir($dir), array('.','..') );
		foreach ($files as $file) 
		{
			if (is_dir("$dir/$file") && $file[0] != "_" )
			{
				$folders[$file] = $this->getSubfolders("$dir/$file");
			}
		}
		error_reporting(E_ALL);
		if(count($folders) == 0)
			return null;
		return $folders;
	}

	private static function getFilesInFolder( $folder )
	{
		$path = self::getFilesFolderName() . "/" . $folder;
		if(!is_dir($path)) return null;

		$result = Array();
		$files = array_diff(scandir($path), array('.','..'));
		foreach ($files as $file) {
			if (!is_dir($path . "/$file"))
				$result[$file] = filesize($path . "/$file");
		}
		return $result;
	}

	private static function fileExist( $path )
	{
		return is_file(self::getFilesFolderName() . "/" . $path);
	}

	private static function getFileSize( $path )
	{
		return filesize(self::getFilesFolderName() . "/" . $path);
	}

	private static function folderExist($path)
	{
		return is_dir( self::getFilesFolderName() . "/" . $path );
	}

	private static function delTree($dir)
	{
		$path = self::getFilesFolderName() . "/";
		if($path == "/") return;

		$files = array_diff(scandir($path . $dir), array('.','..'));
		foreach ($files as $file) {
		  (is_dir($path . "$dir/$file")) ? self::delTree("$dir/$file") : unlink($path . "$dir/$file");
		}
		return rmdir($path . $dir);
	} 

	private static function createFolder( $dirname, $force = false)
	{
		$subfolders = explode("/",$dirname);
		$num = count($subfolders);

		$current = "/";

		for( $i = 0; $i < $num; $i++)
		{
			$subdir = $subfolders[ $i ];

			//already exist
			if( is_dir(self::getFilesFolderName() . $current . $subdir) )
			{
				//last subfolder
				if( $i == ($num - 1) )
				{
					if($force)
						self::delTree($subdir);
					else
						return;
				}
			}
			else //do not exist? create
			{
				mkdir( self::getFilesFolderName() . $current . $subdir );  
				chmod( self::getFilesFolderName() . $current . $subdir, 0775);
			}

			$current .= $subdir . "/";
		}
	}

	private static function writeFile($fullpath, $data)
	{
		$finalpath = self::getFilesFolderName() . "/" . $fullpath;
		$result = file_put_contents( $finalpath , $data );
		if($result === FALSE)
			return false;
		debug("File written, size: " . filesize( $finalpath ) );
		return true;
		/*
		$fp = fopen(  self::getFilesFolderName() . "/" . $fullpath , 'wb');
		if ($fp)
		{
			fwrite($fp,$data);
			fclose($fp);
			return true;
		}
		return false;
		*/
	}

	private static function writeFilePart($fullpath, $data, $offset)
	{
		if( strlen($data) == 0 )
		{
			debug( "Error: No Data" );
			return false;
		}

		$finalpath = self::getFilesFolderName() . "/" . $fullpath;

		//debug($finalpath);
		$old_size = filesize($finalpath);

		$fp = fopen( $finalpath, 'r+');
		if( !$fp )
			return false;
		if( fseek( $fp, intval($offset), SEEK_SET ) != 0 )
		{
			debug( "Error seeking in file" );
			fclose( $fp );
			return false;
		}
		fwrite( $fp, $data );
		fclose( $fp );

		//debug( "Data size: ". strlen($data) );
		//debug( "Before: ". $old_size ." After: " . filesize($finalpath));

		return true;
	}

	private static function createEmptyFile( $fullpath, $size = 0 )
	{
		$finalpath = self::getFilesFolderName() . "/" . $fullpath;
		
		$fp = fopen( $finalpath, 'w');
		if(!$fp)
			return false;
		if($size)
		{
			fseek($fp, intval($size)-1,SEEK_SET); 
			fwrite($fp,"\0"); //write end
		}
		fclose($fp);

		return true;
	}

	public static function copyFile($old, $new)
	{
		if( !is_file( self::getFilesFolderName() . "/" . $old ) )
		{
			debug("File not found in HD: " . self::getFilesFolderName() . "/" . $old );
			return false;
		}

		$result = copy( self::getFilesFolderName() . "/" .  $old, self::getFilesFolderName() . "/" . $new );
		if(!$result)
			debug("Error in copyFile, copy not performed to: " . self::getFilesFolderName() . "/" . $new );
		return $result;
	}

	//also works for renaming
	public static function moveFile($old, $new)
	{
		if( !is_file( self::getFilesFolderName() . "/" . $old ) )
		{
			debug("File not found in HD: " . self::getFilesFolderName() . "/" . $old );
			return false;
		}

		$result = rename( self::getFilesFolderName() . "/" .  $old, self::getFilesFolderName() . "/" . $new );
		if(!$result)
			debug("File cannot be moved to : " . self::getFilesFolderName() . "/" . $new );
		return $result;
	}

	public static function deleteFile($filename)
	{
		if ( is_file( self::getFilesFolderName() . "/" .  $filename ) )
			return unlink( self::getFilesFolderName() . "/" . $filename );
		return false;
	}

	public static function downloadFile($url)
	{
		$tmp_filename = tempnam(__DIR__ . "/../../tmp/","tmp");

		//I use curl instead of curl or get_content because they dont seem safe enough
		$fp = fopen ( $tmp_filename, 'w+'); 
		if($fp == false)
		{
			debug("File cannot be opened: " . $tmp_filename);
			return null;
		}

		$ch = curl_init( str_replace(" ","%20", $url) );//Here is the file we are downloading, replace spaces with %20
		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 50);
		curl_setopt($ch, CURLOPT_FILE, $fp ); // write curl response to file
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_exec($ch); // get curl response
		fclose($fp);

		if(curl_errno($ch) != 0) //error found
		{
			$info = curl_getinfo($ch);
			//var_dump( $info );
			debug("CURL error: " . $info["http_code"] . " -> " . $url );
			curl_close( $ch );
			unlink( $tmp_filename );
			return null;
		}

		$info = curl_getinfo($ch);

		$file_data = new stdClass();
		$file_data->size = intval( $info["download_content_length"] );
		$file_data->type = trim( $info["content_type"] ); //could be useful

		debug("Filetype: " . $file_data->type );

		curl_close( $ch );

		$file_data->data = file_get_contents( $tmp_filename );
		unlink( $tmp_filename );
		debug($tmp_filename);

		return $file_data;
	}

	//************ DB *****************************
	public function getDBFolders($unit_id)
	{
		$database = getSQLDB();
		$query = "SELECT DISTINCT `virtualfolder` FROM `".DB_PREFIX."files` WHERE `unit_id` = ". intval($unit_id);
		$result = $database->query( $query );
		$folders = Array();
		//debug($query);
		if($result != null)
			while($folder = $result->fetch_object())
				$folders[] = $folder->virtualfolder;
		return $folders;
	}

	public function getFileInfoById( $file_id )
	{
		$database = getSQLDB();
		$file_id = intval($file_id);

		$query = "SELECT file.*, unit.name AS unit_name FROM `".DB_PREFIX."files` AS file,`".DB_PREFIX."units` AS unit WHERE file.`id` = ". $file_id ." AND file.unit_id = unit.id LIMIT 1";
		//debug($query);
		$result = $database->query( $query );
		if ($result === false) 
			return null;

		$file_info = $result->fetch_object();
		$file_info->fullpath = $this->clearPathName( $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename );
		return $file_info;		
	}

	public function getFileInfoByFullpath($fullpath)
	{
		$database = getSQLDB();

		$folder = dirname($fullpath);
		$folder = $this->clearPathName( $folder );

		$folders = explode("/",$folder);

		$unit = $this->getUnitByName( $folders[0] );
		if(!$unit)
			return null;

		//remove first element, the unit name
		array_shift( $folders ); 
		$folder = implode("/",$folders);

		$unit_id = $unit->id;
		$filename = basename($fullpath);

		$query = "SELECT file.*, unit.name AS unit_name FROM `".DB_PREFIX."files` AS file,`".DB_PREFIX."units` AS unit WHERE `unit_id` = ".intval($unit_id)." AND `folder` = '". $folder."' AND `filename` = '". $filename."' AND file.unit_id = unit.id LIMIT 1";

		$result = $database->query( $query );
		if (!$result)
			return null;

		$file_info = $result->fetch_object();
		$file_info->fullpath = $this->clearPathName( $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename );
		return $file_info;		
	}

	public function getFilesFromDB($unit_id, $folder = "", $subfolders = false, $usernames = true)
	{
		$folder = $this->clearPathName( $folder );
		$folder = addslashes($folder);

		$database = getSQLDB();

		$subfolders_str = $subfolders ? " OR `folder` = '" . $folder . "/%' " : "";

		if($usernames)
			$query = "SELECT files.*, users.username AS author_username FROM `".DB_PREFIX."files` AS files, `".DB_PREFIX."users` AS users WHERE (`folder` = '" . $folder . "' " . $subfolders_str .") AND unit_id = ".intval($unit_id) ." AND users.id = files.author_id";
		else
			$query = "SELECT * FROM `".DB_PREFIX."files` WHERE (`folder` = '" . $folder . "' " . $subfolders_str .") AND unit_id = ".intval($unit_id);
		
		$result = $database->query( $query );
		if ($result === false) 
			return null;

		$files = Array();
		while($file = $result->fetch_object())
		{
			//cannot get unit name unless I modify the query
			$files[] = $file;
		}

		//debug($query);
		return $files;		
	}

	public function searchFilesFromDBByFilename($unit_id, $wildcard, $limit = 50, $offset = 0)
	{
		$wildcard = addslashes($wildcard);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."files` WHERE `filename` = '" . $wildcard . "' AND unit_id = ".intval($unit_id) . " LIMIT " . intval($offset) . "," . intval($limit);
		//debug($query);

		$result = $database->query( $query );
		if (!$result) 
			return null;

		$files = Array();
		while($file = $result->fetch_object())
			$files[] = $file;

		return $files;		
	}

	public function searchFilesFromDBByCategory($unit_id, $category, $limit = 100, $offset = 0)
	{
		$category = addslashes($category);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."files` WHERE `category` = '" . $category . "' AND unit_id = ".intval($unit_id) . " LIMIT " . intval($offset) . "," . intval($limit);
		//debug($query);

		$result = $database->query( $query );
		if (!$result) 
			return null;

		$files = Array();
		while($file = $result->fetch_object())
			$files[] = $file;

		return $files;		
	}


	public function recomputeUnitSize( $unit_id = -1 )
	{
		$unit_id = intval($unit_id);

		$database = getSQLDB();
		$query = "SELECT `unit_id`, SUM(`size`) AS total_size FROM `".DB_PREFIX."files` GROUP BY `unit_id`";
		
		if( $unit_id != -1 )
			$query .= " WHERE `unit_id` = " . $unit_id;

		$result = $database->query( $query );
		if (!$result) 
			return false;
		
		while( $row = $result->fetch_object() )
		{
			$id = $row->unit_id;
			$total_size = $row->total_size;
			debug( "ID: " . $id . " Size: " . $total_size );
			$query = "UPDATE `".DB_PREFIX."units` SET `used_size` = ".$total_size." WHERE `id` = ".$id;
			$result2 = $database->query( $query );
			if (!$result2)
				return false;
		}

		return true;
	}

	public function actionDebug()
	{
		$r = $this->recomputeUnitSize();

		if( $r )
			$this->result["status"] = 1;
		else
			$this->result["status"] = -1;
	}

	/*
	public function saveBinaryFile($filename, $data, $size = 0)
	{
		global $lwhs_filespath;
		//error_reporting(0);
		//error_reporting(E_ERROR | E_WARNING | E_PARSE);

		$fp = fopen( $lwhs_filespath . $filename, 'wb');
		if ($fp)
		{
			fwrite($fp,$data);
			fclose($fp);

			//check file size
			$this->result["size"] = filesize($lwhs_filespath.$filename);
			if ($size != 0 && $this->result["size"] != $size )
			{
				$this->result["error"] = "problem on saving, sizes do not match";
				return false;
			}
			else
			{
				$this->result["msg"] = "file saved";
				$this->result["filename"] = $filename;
			}
		}
		else
		{
			$this->result["error"] = "cannot write file";
			return false;
		}
		
		error_reporting(E_ERROR | E_WARNING | E_PARSE);
		return true;
	}
	*/

};

//make it public
registerModule("files", "FilesModule" );
?>
