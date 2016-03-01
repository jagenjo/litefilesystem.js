# litefileserver.js

LiteFileServer.js is front-end and back-end library that allows javascript apps to store resources (images, text-files, binaries) in the server.
It comes with its own users and units system that allow every user to partition its own space and share it among other users.

Some of the features:

 * REST HTTP API for storing, listing, moving, updating or deleting files.
 * Basic users (register, login, delete, administration )
 * Independent file tree per user
 * Units, users can have several units to store files and share with other users
 * Files can have thumbnail image and metadata

The server side is coded in PHP and comes with useful scripts for administration.

Installing
----------

Create a copy of config.sample.php from include/ and name it config.php, edit the file with the info for your database and folders.

Be sure that the folder is in the same group as your HTTP server (usually www-data). To ensure that you can run this command:
```su chown -R :www-data litefileserver_folder```

Run the install.php script (can be run from the browser or the CLI)

Feedback
--------

You can write any feedback to javi.agenjo@gmail.com




