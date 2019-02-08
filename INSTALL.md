# Installing LiteFileServer

## Requirements

To install LFS in your server you need to be sure to fulfill the next requirements:

- any HTTP server (apache, nginx, ...)
- php5 or higher
- mySQL 4.0 or higher

## Copy files

Now you must copy the ```src/``` folder from the repository to your machine.

Be sure that the folder and the files are in the same group as your HTTP server (usually www-data). To ensure that you can run this command:
```bash
chown -R :www-data litefileserver_folder
```

## Edit the config file

The ```include/config.php``` must contain the database name, password and some configuration options, but it is not supplied. 
So you must take the ```config.sample.php``` in the ```include``` folder and rename it as ```config.php```, then edit it so it 
so it contains all the data. 

Here are the important fields you must edit:

```php
define('HOST_URL',''); //the url to your website
define('MAIL_ADDRESS','foo@site.com'); //a mail address to send info about pending users waiting for registration
// SQL database *********************
define('DB_NAME', ''); //the mysql database name
define('DB_USER', ''); //the mysql database username
define('DB_PASSWORD', ''); //the mysql database password
define('DB_HOST', 'localhost'); //the database host

define('ADMIN_PASS',''); //CHANGE THIS
define('ADMIN_MAIL',''); //CHANGE THIS
```

It is also important to change the salts so your password cannot be broken
```php
//System ****************************
define('GLOBAL_PASS_SALT','any random string, with 20 chars are enough'); //ENTER SOMETHING RANDOM HERE
define('UNITNAME_SALT','another random string, with 20 chars are enough'); //ENTER SOMETHING RANDOM HERE
```

## Launch install script

Now you need to launch the script that creates all the tables. 

This script can only be launched once, if it detects there is already an installation it wont work to prevent deleting the existing files.

There is two ways to do it:

### From the console

In the root folder of the installation:

```bash
php install.php
```

I recommend to force the files folder to have the same group as apache:
```bash
chown -R :www-data ../files
```

### From the browser

Open the install.php url


### Reinstalling

If you to reinstall it you can call the script again **but all the files inside the LFS will be lost**.

```bash
php install.php force
```

## Ready

Your version should be ready to be used, check the website and login with the admin user/pass.
