
//CREATE UNIT
$(".create-unit-button").click(function(e){

	$("#new-unit-dialog .inputName").val("newUnit");

	var min = 1024*1024;
	var max = session.user.total_space - session.user.used_space;
	var value = 1024*1024*2;
	if(value > max)
		value = max;

	$("#new-unit-dialog .inputSize").slider({min:min, max: max, step: 1024*1024, value: value, formatter: function(v){ 
		return LFS.getSizeString(v); 
	}});

});

$("#new-unit-dialog .create-button").click(function(e){
	var name = $("#new-unit-dialog .inputName").val();
	if(!name)
		return;
	$("#new-unit-dialog").modal('hide');
	var slider = $("#new-unit-dialog .inputSize")[0];
	var size = slider.value; //$("#new-unit-dialog .inputSize").slider('getValue');
	session.createUnit( name, size, function(unit, resp){
		if(!unit)
			bootbox.alert(resp.msg);
		if(resp.user)
			refreshUserInfo( resp.user );
		refreshUnits();
	}, function(err){
		bootbox.alert(err || "Cannot create unit");
	});
});


$("#new-unit-dialog .invitation-button").click(function(e){
	var token = $("#new-unit-dialog .inputInvitationToken").val();
	if(!token)
		return;
	$("#new-unit-dialog").modal('hide');
	session.joinUnit( token, function(unit, resp){
		if(!unit)
			bootbox.alert(resp.msg);
		if(resp.user)
			refreshUserInfo( resp.user );
		refreshUnits();
	}, function(err){
		bootbox.alert(err || "Cannot join unit");
	});
});

$(".refresh-units-button").click(function(e){
	refreshUnits();
});

//SETUP UNIT
var save_setup_ladda = Ladda.create( $("#setup-unit-dialog .save-unit-setup-button")[0] );


$(".setup-unit-button").click(function(e){
	console.log("setup");
	var unit = units[ current_unit ];
	if(!unit)
		return;

	save_setup_ladda.stop();

	if(!session)
		return;

	var available_space = session.user.total_space - session.user.used_space + unit.total_size;

	$("#setup-unit-dialog .size-info").html( LFS.getSizeString( unit.used_size ) + " / " + LFS.getSizeString( available_space ) );

	refreshSlider( unit );

	refreshUnitSetup(current_unit);
});

//updates the unit slider
function refreshSlider( unit )
{
	var value = unit.total_size;
	var unused = (unit.total_size - unit.used_size);
	var min = system_info.unit_min_size;
	var max = session.user.total_space - session.user.used_space + unit.total_size;
	if(max > system_info.unit_max_size)
		max = system_info.unit_max_size;

	$("#setup-unit-dialog .inputSize").slider({ min:min, max: max, step: 1024*1024, value: value, formatter: function(v){ 
		return LFS.getSizeString(v);
	}});
}

$(".save-unit-setup-button").click(function(e){
	e.preventDefault();
	e.stopPropagation();
	save_setup_ladda.start();

	var metadata = units[ current_unit ].metadata;
	metadata.name = $("#setup-unit-dialog .inputName").val();
	var slider = $("#setup-unit-dialog .inputSize")[0];
	var size = parseInt(slider.value); //.slider('getValue');

	var info = {
		metadata: metadata,
		total_size: size
	};

	if(size > system_info.unit_max_size)
		size = system_info.unit_max_size;

	session.setUnitInfo( current_unit, info, function(v,resp){
		save_setup_ladda.stop();
		if(resp.unit)
			refreshUnitInfo( resp.unit );
		if(resp.user)
			refreshUserInfo( resp.user );
		if(!v)
			bootbox.alert(resp.msg);
		$("#setup-unit-dialog").modal("hide");
	});
});

function refreshUnitSetup( unit_name )
{
	var unit = units[ unit_name ];
	$("#setup-unit-dialog .inputName").val( unit.metadata.name );
	$("#setup-unit-dialog .inputInviteToken").val( unit.invite_token );
	var root = $("#setup-unit-dialog .users");

	var template = $("#setup-unit-dialog .unit-user-item.template");

	session.getUnitInfo( unit_name , function(unit){


		$("#setup-unit-dialog .unitname").html( unit_name );
		refreshSlider( unit );

		root.empty();

		if(unit.users)
		{
			$("#setup-unit-dialog .users-list").show();
			for(var i in unit.users)
			{
				var user = unit.users[i];

				var row = template.clone();
				row.removeClass("template");
				row.find(".username").html( user.username );
				row.find(".role").html( user.mode == "ADMIN" ? "Administrator" : user.mode );
				row[0].dataset["username"] = user.username;
				row[0].dataset["unit"] = unit_name;

				if( user.username == session.user.username)
					row.find(".dropdown").remove();

				if(user.mode == "ADMIN")
				{
					row.addClass("admin-role");
					root.prepend(row);
				}
				else
					root.append(row);
			}
		}
		else
		{
			$("#setup-unit-dialog .users-list").hide();
		}

		if( unit.author_id == session.user.id )
		{
			$("#setup-unit-dialog .only-author").show();
			$("#setup-unit-dialog .slider").show();
			$("#setup-unit-dialog .leave-unit-button").hide();
		}
		else
		{
			$("#setup-unit-dialog .only-author").hide();
			$("#setup-unit-dialog .slider").hide();
			$("#setup-unit-dialog .leave-unit-button").show();
		}

		//bind user button to remove from unit
		$(".remove-user-unit-button").click(function(){
			var username = this.parentNode.parentNode.parentNode.parentNode.dataset["username"];
			bootbox.confirm("Are you sure?", function(v){
				if(!v)
					return;
				session.removeUserFromUnit( current_unit, username, function(status, resp){
					if(status)
						refreshUnitSetup( current_unit );						
					else
						bootbox.alert( resp.msg );
				});
			});
		});

	});
}


$("#setup-unit-dialog .invite-user-button").click(function(e){
	e.preventDefault();
	e.stopPropagation();

	if(!session)
		return;

	bootbox.prompt({ title:"User name or email address",
		value: "",
		callback: function(result) {
			if(!result)
				return;

			session.inviteUserToUnit( current_unit, result, function(status, resp){
				if(status)
					refreshUnitSetup( current_unit );
				else
					bootbox.alert( resp.msg );
			}, function(err){
				bootbox.alert(err || "Cannot invite to unit");
			});
		}
	}); 
});	

$("#setup-unit-dialog .delete-unit-button").click(function(e){
	e.preventDefault();
	e.stopPropagation();

	bootbox.confirm("Are you sure? all files will be deleted", function(r){
		if(!r)
			return;

		session.deleteUnit( current_unit, function(status,resp){
				if(status)
				{
					refreshUnits();
					refreshUserInfo( resp.user );
					$("#setup-unit-dialog").modal("hide");
				}
				else
					bootbox.alert( resp.msg );
		});
	});
});

$("#setup-unit-dialog .leave-unit-button").click(function(e){
	e.preventDefault();
	e.stopPropagation();

	bootbox.confirm("Are you sure? you wont have access to this unit", function(r){
		if(!r)
			return;

		session.leaveUnit( current_unit, function(status,resp){
				if(status)
				{
					refreshUnits();
					refreshUserInfo( resp.user );
					$("#setup-unit-dialog").modal("hide");
				}
				else
					bootbox.alert( resp.msg );
		});
	});
});


