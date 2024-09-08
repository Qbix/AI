<?php
function AI_estimateFaces_response_invitation ($params) {
	$request = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(["token"], $request, true);
	$token = $request['token'];

	$invite = new Streams_Invite();
	$invite->token = $token;
	if (!$invite->retrieve()) {
		throw new Exception("Token invalid!");
	}

	$imagePath = implode(DS, [
		APP_FILES_DIR,
		Q::app(),
		'uploads',
		'Streams',
		'invitations',
		Q_Utils::splitId($invite->invitingUserId),
		'Streams',
		'image',
		'invite',
		$token,
		Q_Config::expect('Q', 'images', 'Streams/invite/groupPhoto', 'defaultSize').'.png'
	]);
	if (!is_file($imagePath)) {
		throw new Exception("Group photo not found!");
	}

	Q_Utils::sendToNode(array(
		"Q/method" => "AI/Image/estimateFaces",
		"imagePath" => $imagePath,
		"publisherId" => $invite->invitingUserId,
		"streamName" => "Streams/image/invite/".$token
	));
}