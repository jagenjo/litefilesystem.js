(function(global){

var LiteFileServer = {
	version: "0.1a",
	url: "./server.php",
	files_path: "./files/",
	pics_path: "./pics/",
	previews: "local", //generate previews in local/server
	generate_preview: true,
	preview_size: 128,

	NOT_LOGGED: 0,
	WAITING: 1,
	LOGGED: 2,

	TOKEN_NAME: "lfs_token", //used for the local storage storing

	//create a session
	login: function( username, password, on_complete)
	{
		//create session
		var session = new LiteFileServer.Session();
		session.url = this.url;
		session.status = LiteFileServer.WAITING;

		//avoid sending the login plain in the form with a catchy name
		var userpass = btoa( username + "|" + password );

		//fetch info
		this.request(this.url, { action:"user/login", loginkey: userpass}, function(resp){
			console.log(resp);
			session.last_resp = resp;
			session.user = resp.user;
			session.status = resp.status == 1 ? LiteFileServer.LOGGED : LiteFileServer.NOT_LOGGED;
			if(resp.session_token)
				session.setToken(resp.session_token);
			if(on_complete)
				on_complete(session, resp);
		});

		return session;
	},

	//get server info status and config
	checkServer: function( on_complete )
	{
		console.log("Checking Server");
		this.request(this.url, { action:"system/ready" }, function(resp) {
			LFS.system_info = resp.info;
			LFS.files_path = resp.info.files_path;
			LFS.pics_path = resp.info.pics_path;
			console.log(resp);
			if(on_complete)
				on_complete(resp);
		}, function(error){
			console.log("Error Checking Server");
			if(on_complete)
				on_complete(null, error);
		});
	},

	checkExistingSession: function( on_complete )
	{
		var old_token = localStorage.getItem( LiteFileServer.TOKEN_NAME );
		if(!old_token)
		{
			if(on_complete)
				on_complete(null);
			return;
		}

		this.request( this.url,{action: "user/checkToken", token: old_token}, function(resp){
			if(!resp.user)
				localStorage.removeItem( LiteFileServer.TOKEN_NAME );

			if(!on_complete)
				return;

			if(resp.user)
			{
				var session = new LiteFileServer.Session();
				session.url = LiteFileServer.url;
				session.status = LiteFileServer.LOGGED;
				session.user = resp.user;
				session.token = old_token;
				on_complete(session);
			}
			else
				on_complete(null);
		});
	},

	//create a new account if it is enabled
	createAccount: function(user, password, email, on_complete)
	{
		this.request( this.url,{action: "user/create", username: user, password: password, email: email }, function(resp){
			console.log(resp);
			if(on_complete)
				on_complete( resp.status == 1, resp );
		});
	},

	generatePreview: function( file, on_complete )
	{
		var reader = new FileReader();
		reader.onload = loaded;
		reader.readAsDataURL(file);

		var img = null;

		function loaded(e)
		{
			img = new Image();
			img.src = e.target.result;
			img.onload = ready;
		}

		function ready()
		{
			var canvas = document.createElement("canvas");
			canvas.width = canvas.height = LFS.preview_size;
			var ctx = canvas.getContext("2d");
			var f = LFS.preview_size / (img.width > img.height ? img.width : img.height);
			ctx.scale(f,f);
			ctx.drawImage(img,0,0);
			var dataURL = canvas.toDataURL("image/jpeg");
			if(on_complete)
				on_complete(dataURL, img, canvas);
		}
	},

	//http request wrapper
	request: function(url, params, on_complete, on_error, on_progress )
	{
		var xhr = new XMLHttpRequest();
		xhr.open( params ? 'POST' : 'GET' , url, true );

		var formdata = null;
		if(params)
		{
			var formdata = new FormData();
			for(var i in params)
				formdata.append(i, params[i]);
		}

		xhr.onload = function()
		{
			var response = this.response;
			//console.log(response);
			if(this.status < 200 || this.status > 299)
			{
				if(on_error)
					on_error(this.status);
				return;
			}

			var type = this.getResponseHeader('content-type');
			if(type == "application/json")
				response = JSON.parse(response);

			if(on_complete)
				on_complete(response);
			return;
		}

		xhr.onerror = function(err)
		{
			if(on_error)
				on_error(err);
		}

		if(on_progress)
			xhr.upload.addEventListener("progress", function(e){
				var progress = 0;
				if (e.lengthComputable)
					progress = e.loaded / e.total;
				on_progress(progress, e);
			}, false);

		xhr.send(formdata);
		return xhr;
	},

	clearPath: function(path)
	{
		var t = path.split("/");
		t = t.filter( function(v) { return !!v;} );
		return t.join("/");
	},

	parsePath: function(fullpath, is_folder)
	{
		fullpath = this.clearPath(fullpath); //remove slashes
		var t = fullpath.split("/");
		var unit = t.shift();
		var filename = "";
		if(!is_folder)
			filename = t.pop();
		var folder = t.join("/");
		return {
			unit: unit,
			folder: folder,
			filename: filename,
			getFullpath: function() { return this.unit + "/" + this.folder + "/" + this.filename }
		};
	},

	getThumbPath: function(fullpath)
	{
		return this.pics_path + "/" + btoa( this.clearPath( fullpath ) ) + ".jpg";
	},

	requestFile: function(fullpath, on_complete, on_error)
	{
		this.request( this.files_path + "/" + fullpath, null, on_complete, on_error );
	}
};
	
//session
function Session()
{
	this.onsessionexpired = null; //"token not valid"
}

LiteFileServer.Session = Session;

//bypass adding the token
Session.prototype.request = function(url, params, on_complete, on_error, on_progress )
{
	if(!this.token)
	{
		console.warn("LFS: not logged in");
		if(on_error)
			on_error(null);
		return;
	}

	params = params || {};
	params.token = this.token;
	var that = this;
	return LiteFileServer.request( url, params, function(resp){
		if(resp.status == -1 && resp.msg == "token not valid")
		{
			if(that.onsessionexpired)
				that.onsessionexpired( that );
		}
		if(on_complete)
			on_complete(resp);
	}, on_error, on_progress );
}

//assign token
Session.prototype.setToken = function(token)
{
	this.token = token;
	//save token
	localStorage.setItem( LiteFileServer.TOKEN_NAME , token );
}

//accounts
Session.prototype.logout = function(on_complete, on_error)
{
	if(	localStorage.getItem( LiteFileServer.TOKEN_NAME ) == this.token)
		localStorage.removeItem( LiteFileServer.TOKEN_NAME );	

	return this.request( this.url,{action: "user/logout" }, function(resp){
		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}
		if(on_complete)
			on_complete(resp.status == 1);
	});
}

Session.prototype.removeAccount = function(user_id, on_complete)
{
	//TODO
}


//units
Session.prototype.createUnit = function(unit_name, size, on_complete)
{
	return this.request( this.url,{action: "files/createUnit", unit_name: unit_name, size: size }, function(resp){
		if(on_complete)
			on_complete(resp.unit, resp);
	});
}

Session.prototype.shareUnit = function(unit_name, username, on_complete)
{
	return this.request( this.url,{action: "files/shareUnit", unit_name: unit_name, username: username }, function(resp){
		if(on_complete)
			on_complete(resp.status == 1, resp);
	});
}


Session.prototype.getUnitInfo = function(unit_name, on_complete)
{
	return this.request( this.url,{action: "files/getUnitInfo", unit_name: unit_name }, function(resp){
		if(on_complete)
			on_complete(resp.unit);
	});
}

Session.prototype.getUnits = function(on_complete)
{
	return this.request( this.url,{action: "files/getUnits"}, function(resp){
		if(resp.data)
		{
			for(var i in resp.data)
			{
				var unit = resp.data[i];
				unit.used_size = parseInt( unit.used_size );
				unit.total_size = parseInt( unit.total_size );
				unit.metadata = JSON.parse(unit.metadata);
			}
		}

		if(on_complete)
			on_complete(resp.data);
	});
}

//folders
Session.prototype.getFolders = function( unit, on_complete, on_error )
{
	return this.request( this.url,{ action: "files/getFolders", unit: unit }, function(resp){
		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		if(on_complete)
			on_complete(resp.data);
	});
}

Session.prototype.createFolder = function( fullpath, on_complete, on_error )
{
	return this.request( this.url,{action: "files/createFolder", fullpath: fullpath }, function(resp){

		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		if(on_complete)
			on_complete(resp.data);
	});
}

//files
Session.prototype.getFiles = function( unit, folder, on_complete, on_error )
{
	return this.request( this.url,{ action: "files/getFilesInFolder", unit: unit, folder: folder }, function(resp){

		if(resp.status < 0)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		for(var i in resp.data)
		{
			var file = resp.data[i];
			//file.getLink = function() { return this.fullpath; }
			file.folder = folder;
			file.fullpath = unit + "/" + folder + "/" + file.filename;
		}
		if(on_complete)
			on_complete(resp.data);
	});
}

Session.prototype.getFilesByPath = function( fullpath, on_complete, on_error )
{
	return this.request( this.url,{ action: "files/getFilesInFolder", fullpath: fullpath }, function(resp){

		if(resp.status < 0)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		for(var i in resp.data)
		{
			var file = resp.data[i];
			file.fullpath = resp.unit + "/" + resp.folder + "/" + file.filename;
		}
		if(on_complete)
			on_complete(resp.data);
	});
}

Session.prototype.searchByCategory = function( category, on_complete )
{
	return this.request( this.url,{ action: "files/searchByCategory", category: category }, function(resp){
		if(on_complete)
			on_complete(resp.data);
	});
}

Session.prototype.getFileInfo = function( fullpath, on_complete )
{
	return this.request( this.url,{ action: "files/getFileInfo", fullpath: fullpath }, function(resp){
		if(on_complete)
			on_complete(resp.data);
	});
}

//actions
Session.prototype.uploadFile = function( unit, folder, filename, data, category, on_complete, on_error, on_progress )
{
	category = category || "TEXT";

	var encoding = "";
	if( typeof(data) != "string" )
		encoding = "file";

	var ext = filename.split('.').pop().toLowerCase();
	var extensions = ["png","jpg","jpeg"];

	var params = { action: "files/uploadFile", unit: unit, folder: folder, filename: filename, category: category, encoding: encoding, data: data };
	var that = this;

	if(LFS.generate_preview && LFS.previews == "local" && extensions.indexOf(ext) != -1 )
	{
		LFS.generatePreview( data, function( prev_data ) {
			params.preview = prev_data;
			that.request( that.url, params, on_resp, on_error, on_progress );
		});
	}
	else
		return this.request( this.url, params, on_resp, on_error, on_progress );

	function on_resp(resp)
	{
		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		if(on_complete)
			on_complete(resp.status == 1, resp);
	}
}

Session.prototype.uploadFileByPath = function( fullpath, data, category, on_complete, on_error, on_progress )
{
	var info = LFS.parsePath( fullpath );
	return this.uploadFile( info.unit, info.folder, info.filename, data, category, on_complete, on_error, on_progress );
}

Session.prototype.deleteFile = function( fullpath, on_complete, on_error )
{
	return this.request( this.url,{ action: "files/deleteFile", fullpath: fullpath }, function(resp){

		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		if(on_complete)
			on_complete(resp.status == 1, resp);
	}, on_error );
}

Session.prototype.updateFileContent = function( fullpath, data, on_complete, on_error )
{
	return this.request( this.url,{ action: "files/updateFile", fullpath: fullpath, data: data }, function(resp){

		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		if(on_complete)
			on_complete(resp.data);
	}, on_error );
}

Session.prototype.moveFile = function( fullpath, new_fullpath, on_complete, on_error )
{
	return this.request( this.url,{ action: "files/moveFile", fullpath: fullpath, new_fullpath: new_fullpath }, function(resp){

		if(resp.status != 1)
		{
			if(on_error)
				on_error(resp.msg);
			return;
		}

		if(on_complete)
			on_complete(resp.data, resp);
	}, on_error );
}

global.LFS = global.LiteFileServer = LiteFileServer;

})(window);