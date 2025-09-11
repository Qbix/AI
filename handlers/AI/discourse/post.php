<?php

function AI_discourse_post()
{
	// check authorization
	$authorized = Q_Config::get('Users', 'discourse', 'requireAuthorizedRole', false);
	if ($authorized && !Users::roles(null, $authorized)) {
		throw new Users_Exception_NotAuthorized();
	}

	Q_Request::requireFields(array('userId', 'apiKey', 'topicUrl', 'attitude'), true);
	$userId   = $_REQUEST['userId'];
	$topicUrl = $_REQUEST['topicUrl'];
	$apiKey   = $_REQUEST['apiKey'];
	$attitude = $_REQUEST['attitude'];

	// language preference
	if ($language = Q::ifset($_REQUEST, 'language', '')) {
		$info         = Q_Text::languagesInfo();
		$languageName = Q::ifset($info, $language, 'name', 'en');
	} else {
		$languageName = '';
	}

	// normalize URL
	$parts    = explode('?', $topicUrl);
	$topicUrl = reset($parts);
	if (!preg_match('/(.*)(\/t\/)(.*)/', $topicUrl, $matches)) {
		throw new Q_Exception_WrongType(array(
			'field' => 'topicUrl',
			'type'  => 'a valid Discourse topic URL'
		));
	}
	$baseUrl = $matches[1];
	$tail    = $matches[3];

	// extract topic id and post number
	if (preg_match('/(.*)\/(.*)\/(.*)/', $tail, $matches)) {
		$topicId           = $matches[1];
		$replyToPostNumber = intval($matches[3]);
	} else {
		$topicId           = $tail;
		$replyToPostNumber = 1; // first post
	}

	// ensure user exists
	Q::event('Users/discourse/post', compact('apiKey', 'userId', 'baseUrl'));

	// get topic contents
	$uxt = new Users_ExternalTo_Discourse(array(
		'userId'   => $userId,
		'platform' => 'discourse',
		'appId'    => $baseUrl
	));
	$uxt->retrieve();
	$uxt->setExtra(compact('baseUrl', 'apiKey'));

	$ret   = $uxt->getTopic($topicUrl);
	$posts = Q::ifset($ret, 'post_stream', 'posts', array());

	// find the post matching post_number
	$target = null;
	foreach ($posts as $p) {
		if (isset($p['post_number']) && $p['post_number'] == $replyToPostNumber) {
			$target = $p;
			break;
		}
	}
	if (!$target) {
		throw new Q_Exception_MissingField(array(
			'field' => "post_number $replyToPostNumber",
			'type'  => 'Discourse post'
		));
	}

	// convert HTML to simple markdown
	$rawHtml = $target['cooked'];
	$input   = Q_Html::toSimpleMarkdown($rawHtml, 1000);

	$topicTitle = $ret['title'];
	$username   = $uxt->getExtra('username');

	$postOrComment = ($replyToPostNumber > 1) ? 'comment' : 'post';
	$byAuthor      = isset($target['name']) ? ' by ' . $target['name'] : '';

	$languageClause = $languageName
		? "Speak using $languageName language"
		: "Speak in the same language as the $postOrComment";

	// generate response with LLM
	$LLM = new AI_LLM_OpenAI();
	$instructions = array(
		'agree + actionable' => "What follows is HTML of a $postOrComment$byAuthor. Write one or two paragraphs expressing agreement with the post, without explicitly saying the words 'I agree'. Add interesting insights or ideas that can accomplish what is being discussed. The post:\n" . $input,
		'agree + emotive' => "What follows is HTML of a $postOrComment$byAuthor. Write one paragraph expressing agreement with the post. Be enthusiastic and express emotion about the subject.\n$languageClause\nThe post:\n" . $input,
		'agree + expand' => "What follows is HTML of a $postOrComment$byAuthor. Write one sentence expressing general agreement (avoiding saying 'I agree'), but then two paragraphs discussing a much larger and more important vision everyone should consider.\n$languageClause\nThe post:\n" . $input,
		'agree + changeSubject' => "What follows is HTML of a $postOrComment$byAuthor. Write one sentence expressing general agreement (avoiding saying 'I agree'), but then two paragraphs discussing a related but different issue, and explain why it is more important.\n$languageClause\nThe post:\n" . $input,
		'disagree + respectful' => "What follows is HTML of a $postOrComment$byAuthor. Write two sentences explaining the best reasons to disagree with this post. Be very respectful but thorough.\n$languageClause\nThe post:\n" . $input,
		'disagree + emotive' => "What follows is HTML of a $postOrComment$byAuthor. Write two sentences explaining the best reasons to disagree with this post. Use spunky and emotional language, and be opinionated, citing well known aphorisms.\n$languageClause\nThe post:\n" . $input,
		'disagree + absurd' => "What follows is HTML of a $postOrComment$byAuthor. Write a paragraph in the style of an internet forum, disagreeing with it, by using sarcastic examples and analogies that show why what is being advocated can actually be absurd. Avoid overly formal language and structure, speak plainly and to the point.\n$languageClause\nThe post:\n" . $input,
		'disagree + authority' => "What follows is HTML of a $postOrComment$byAuthor. Write a single paragraph mildly disagreeing with it (without saying 'mildly disagree'), and naming other important authorities on the subject who also disagree, who haven't been mentioned yet, and summarizing their points. Avoid overly formal language and structure, speak plainly and to the point.\n$languageClause\nThe post:\n" . $input
	);

	$messages = array(
		'system' => 'You are a forum member commenting.',
		'user'   => $instructions[$attitude]
	);
	$completions = $LLM->chatCompletions($messages);
	$choices     = Q::ifset($completions, 'choices', array());
	$content     = '';
	foreach ($choices as $choice) {
		$content = $choice['message']['content'];
		break;
	}

	// post back to forum
	$result    = $uxt->postOnTopic($ret['id'], $content);
	$postIndex = Q::ifset($result, 'post_number', null);
	if (isset($postIndex)) {
		$postIndex = $postIndex - 1;
	}

	// save to Streams
	$appId       = Q::app();
	$communityId = Users::communityId();
	$categoryName = 'Streams/external/posts';
	Streams_Stream::fetchOrCreate($communityId, $communityId, $categoryName, array(
		'type' => 'Streams/category'
	));
	Streams::create($communityId, $communityId, 'Streams/external/post', array(
		'title'      => "By $username on $topicTitle",
		'content'    => substr($content, 0, 2000),
		'attributes' => compact('topicUrl', 'topicTitle', 'topicId', 'replyToPostNumber', 'postIndex', 'username', 'userId')
	), array('relate' => array(
		'publisherId' => $communityId,
		'streamName'  => $categoryName,
		'type'        => 'Streams/external/posts',
		'weight'      => time()
	)));

	$username = $uxt->getExtra('username');
	Q_Response::setSlot('data', compact('username', 'content'));
}