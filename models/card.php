<?php

function getCardInfos($cardID, $fields = array('*')) {
	global $db;

	$fields = implode(',', $fields);

	$query = $db->prepare('SELECT '.$fields.'
						FROM cards
						WHERE ca_id = ?');
	$query->execute(array($cardID));
	return $query->fetch(PDO::FETCH_ASSOC);
}

function getCardsInBoard($turnID) {
	global $db;

	$query = $db->prepare('SELECT boards.ca_id
						FROM boards
						WHERE tu_id = ?');
	$query->execute(array($turnID));
	return $query->fetchAll(PDO::FETCH_ASSOC);
}

function getCardsInHand($userID, $turnID) {
	global $db;

	$query = $db->prepare('SELECT c.ca_id, c.ca_name, c.ca_image
						FROM cards as c
						INNER JOIN hands as h
						ON h.ca_id = c.ca_id
						INNER JOIN card_status as ct
						ON ct.ct_id = h.ct_id
						WHERE h.us_id = :userID
						AND h.tu_id = :turnID
						AND ct.ct_name = "En main"');
	$query->execute(array('userID' => $userID,
						'turnID' => $turnID));

	return $query->fetchAll(PDO::FETCH_ASSOC);
}

function isAlreadyInBoard($cardID, $turnID) {
	global $db;

	$query = $db->prepare('SELECT ca_id
						FROM boards
						WHERE ca_id = :cardID
						AND tu_id = :turnID');
	$query->execute(array('cardID' => $cardID,
						'turnID' => $turnID));
	return is_array($query->fetch(PDO::FETCH_ASSOC));
}

function changeHandCardStatus($cardID, $userID, $turnID) {
	global $db;

	$query = $db->prepare('UPDATE hands
						SET ct_id = 2
						WHERE ca_id = :cardID
						AND us_id = :userID
						AND tu_id = :turnID');
	return $query->execute(array('cardID' => $cardID,
						'userID' => $userID,
						'turnID' => $turnID));
}

function addCardInBoard($cardID, $turnID) {
	global $db;

	$query = $db->prepare('INSERT INTO boards(ca_id, tu_id)
						VALUES(:cardID, :turnID)');
	$query->execute(array('cardID' => $cardID,
						'turnID' => $turnID));
}

function addTurnComment($turnID, $comment) {
	global $db;

	$query = $db->prepare('UPDATE turns
						SET tu_comment = :comment
						WHERE tu_id = :turnID');
	$query->execute(array('comment' => $comment,
						'turnID' => $turnID));
}

function getPlayerCardInBoard($turnID, $userID) {
	global $db;

	$query = $db->prepare('SELECT b.ca_id
						FROM boards as b
						INNER JOIN hands as h
						ON h.ca_id = b.ca_id
						INNER JOIN turns as t
						ON t.tu_id = h.tu_id
						INNER JOIN users as u
						ON u.us_id = h.us_id
						WHERE h.tu_id = :turnID
						AND u.us_id = :userID');
	$query->execute(array('turnID' => $turnID,
						'userID' => $userID));
	return $query->fetch(PDO::FETCH_ASSOC);
}

function addGameVote($userID, $cardID, $turnID) {
	global $db;

	$query = $db->prepare('INSERT INTO votes(us_id, ca_id, tu_id)
						VALUES(:userID, :cardID, :turnID)');
	$query->execute(array('userID' => $userID,
						'cardID' => $cardID,
						'turnID' => $turnID));
}