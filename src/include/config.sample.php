<?php

// SQL database *********************
//***********************************

define('DB_NAME', ''); //your database name
define('DB_USER', ''); //your database user
define('DB_PASSWORD', ''); //your database password
define('DB_HOST', 'localhost'); //your database host

define('DB_PREFIX', 'lfs_');
define('DB_REDIS', false ); //use redis database (mostly for sessions), otherwise it will use SQL

//System
define('ADMIN_PASS',''); //CHANGE THIS
define('ADMIN_MAIL',''); //CHANGE THIS
define('GLOBAL_PASS_SALT','pepper salt and other herbs'); //ENTER SOMETHING RANDOM HERE

//config
define('ALLOW_WEB_REGISTRATION', true ); //allow people to create users
define('DEFAULT_USER_SPACE', 10 ); //in MBs
define('VALIDATE_ACCOUNTS', false ); //force people to validate the account once created
define('FILES_PATH','files/');  //folder where all the files will be stored
define('PREVIEW_PREFIX','_th_'); //prefix added to the filename to designate a preview of a file
define('PREVIEW_SUFIX','.jpg');  //sufix added to the filename of every preview
define('VALID_EXTENSIONS','png,jpg,jpeg,bmp,txt,json,js,css,html,htm,ttf,otf');
define('ALLOW_REMOTE_FILE_DOWNLOADING', false );  //allows to upload files that are not in this server

//used to rename categories by file type
$categories_by_type = Array();

?>