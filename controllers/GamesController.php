<?php

useModels(array('user', 'game', 'card', 'gameType'));

define('CARD_PER_PLAYER', 2); //pour tester
define('STORYTELLER_PHASE', 0);
define('BOARD_PHASE', 1);
define('VOTE_PHASE', 2);
define('POINTS_PHASE', 3);

//constante de statut
define('ACTION_IN_PROGRESS', 3);
define('ACTION_DONE', 4);

function index() {
	$partiesEnAttente = getWaintingGames();
	foreach($partiesEnAttente as &$partie) {
		if(isLogged()) {
			$userID = $_SESSION[USER_MODEL][USER_PK];
			$playersInGame = getSpecificArrayValues(getPlayersInGame($partie['ga_id']), 'us_id');
			if(!in_array($userID, $playersInGame)) {
				$partie['action'] = createLink('rejoindre', 'games', 'joinGame', array($partie['ga_id'], $userID), array('title' => 'Rejoindre la partie'));
			}
			else{
				$partie['action'] = createLink('quitter', 'games', 'quiteGame', array($partie['ga_id'], $userID), array('title' => 'Quitter la partie'));
			}
		}
		else {
			$partie['action'] = 'Aucune action possible. ' . createLink('connectez-vous', 'users', 'login', null, array('title' => 'connectez-vous')) . ' pour rejoindre une partie';
		}
	}
	$vars = array('partiesEnAttente' => $partiesEnAttente);
	render('index', $vars);
}

function joinGame($gameID, $userID) {
	if(!isLogged()) {
		setMessage('Vous devez être connecté pour rejoindre une partie', FLASH_ERROR);
		redirect('games');
	}
	else {
		if(checkPlayersInGame($gameID)) {
			if(in_array($userID, getPlayersInGame($gameID))) {
				setMessage('Vous êtes déjà dans cette partie', FLASH_ERROR);
				redirect('games');
			}
			if(!addPlayerInGame($gameID, $userID)) {
				setMessage('Impossible de rejoindre la partie, une erreur interne est survenue.', FLASH_ERROR);
				redirect('games');
			}
			else {
				if(!checkPlayersInGame($gameID)) {
					_startGame($gameID);
				}
				setMessage('Vous avez rejoint la partie', FLASH_SUCCESS);
				redirect('users', 'account');
			}
		}
		else {
			setMessage('Impossible de rejoindre la partie, le nombre maximum de joueurs à été atteint.', FLASH_ERROR);
			redirect('games');
		}
	}
}

function quiteGame($gameID, $userID) {
	if(!isLogged()) {
		setMessage('Vous devez être connecté pour quitter une partie', FLASH_ERROR);
		redirect('games');
	}
	else {
		if($_SESSION[USER_MODEL][USER_PK] != $userID) {
			setMessage('Vous ne pouvez pas faire quitter un autre joueur que vous', FLASH_ERROR);
			redirect('games');
		}
		else if(!isInGame($gameID, $userID)) {
			setMessage('Ce joueur ne joue pas dans cette partie', FLASH_ERROR);
			redirect('games');
		}
		else {
			if(removePlayerFromGame($gameID, $userID)) {
				setMessage('Vous avez bien quitté la partie', FLASH_SUCCESS);
				redirect('games');
			}
			else {
				setMessage('Impossible de quitter la partie, une erreur interne est survenue', FLASH_ERROR);
				redirect('games');
			}
		}
	}
}

function _startGame($gameID) {
	//Récupération des joueurs dans le jeu
	$playersIDS = getPlayersInGame($gameID);

	//Démarre le 1er tour
	$turnID = addTurn($gameID, $playersIDS[0]['us_id']);

	//Récupération du deck associé au type de la partie
	$deck = getDeck($gameID);

	//Distribution des cartes
	$hands = _dealCards($deck, CARD_PER_PLAYER, count($playersIDS));

	$i=0;
	foreach($playersIDS as $player) {
		foreach($hands[$i] as $hand) {
			saveHand($turnID, $player['us_id'], $hand['ca_id']);
		}
		$i++;
	}

	//Sauvegarde de la pioche
	foreach($deck as $card) {
		savePick($gameID, $card['ca_id']);
	}

	//Définition d'un ordre de jeu
	shuffle($playersIDS);

	foreach($playersIDS as $key => $player) {
		definePlayPosition($gameID, $player['us_id'], $key+1);
	}
}

function _dealCards(&$deck, $nbCards, $nbPlayers) {
	if($nbPlayers * $nbCards > count($deck)) {
		trigger_error('Erreur : Pas assez de cartes dans le deck');
		die();
	}
	$hands = array();
	shuffle($deck);

	for($i=0; $i<$nbPlayers; $i++) {
		$hands[] = array_slice($deck, $i*$nbCards, $nbCards);
	}

	$deck = array_slice($deck, $nbPlayers*$nbCards, $nbPlayers*$nbCards);

	return $hands;
}

function play($gameID) {
	if(!isLogged()) {
		setMessage('Vous devez être connecté pour rejoindre une partie', FLASH_ERROR);
		redirect('users', 'login');
	}
	else {
		$userID = $_SESSION[USER_MODEL][USER_PK];
		if(!isInGame($gameID, $userID)) {
			setMessage('Vous ne jouez pas dans cette partie', FLASH_ERROR);
			redirect('games');
		}
		else if(checkPlayersInGame($gameID)) {
			setMessage('Cette partie n\'a pas encore débutée', FLASH_ERROR);
			redirect('games');
		}
		else {
			$storyteller = false; //permet de savoir si le joueur connecté est actuellement le conteur ou non
			$vars = array();
			$currentTurn = getCurrentGameTurn($gameID);
			$phase = _getActualGamePhase($gameID, $currentTurn['tu_id']);
			$gameInfos = getGameInfos($gameID);
			$gameTypeInfos = getGameTypeInfos($gameInfos['gt_id'], array('gt_name'));
			$gameCreatorInfos = getUserInfos($gameInfos['us_id'], array('us_pseudo'));
			$actionStatus;

			$playersInfos = array();
			$i=0;
			foreach(getSpecificArrayValues(getPlayersInGame($gameID), 'us_id') as $playerID) {
				//Récupération des informations des joueurs
				$playersInfos[$i] = getUserInfos($playerID, array('us_id', 'us_pseudo'));
				$playersInfos[$i]['position'] = getOneRowResult(getGameUserPosition($gameID, $playerID), 'pl_position');
				$playersInfos[$i]['points'] = getOneRowResult(getTotalUserPointsInGame($gameID, $playerID), 'nbPoints');

				//Si l'id du joueur vaut celle définie dans la table turns c'est que le joueur est le conteur
				if ($playerID == $currentTurn['us_id']) {
					if($playerID == $_SESSION[USER_MODEL][USER_PK]) $storyteller = true;
					$storytellerInfos = $playersInfos[$i];
					$playersInfos[$i]['role'] = 'conteur';
					$playersInfos[$i]['status'] = ($phase == STORYTELLER_PHASE) ? ACTION_IN_PROGRESS : ACTION_DONE;  
				}
				else {
					$playersInfos[$i]['role'] = 'joueur';
					$playersInfos[$i]['status'] = _checkAction($phase, $playerID, $gameID, $currentTurn['tu_id']);
				}

				//Alors on va récupérer sa main et son statut
				if($playerID == $_SESSION[USER_MODEL][USER_PK]) {
					$actionStatus = _checkAction($phase, $playerID, $gameID, $currentTurn['tu_id']);
					$playersInfos[$i]['hand'] = getCardsInHand($userID, $currentTurn['tu_id']);
				}

				$i++;
			}
			unset($currentTurn['ga_id']);
			unset($gameInfos['gt_id']);
			unset($gameInfos['us_id']);

			$gameInfos['host'] = $gameCreatorInfos['us_pseudo'];
			$currentTurn['game'] = $gameInfos;
			$currentTurn['players'] = $playersInfos;
			$currentTurn['storyteller'] = $storytellerInfos;

			$vars['turn'] = $currentTurn;

			if($storyteller) {
				switch($phase) {
					case STORYTELLER_PHASE:
						$vars['turn']['phase']['title'] = 'Tour du conteur';
						$vars['turn']['phase']['infos'] = 'Vous êtes le conteur ! Sélectionnez l\'une de vos cartes et donner un court indice sur son contenu';
						break;
					case BOARD_PHASE:
						$vars['turn']['phase']['title'] = 'Tour des joueurs';
						$vars['turn']['phase']['infos'] = 'Attendez que les joueurs aient choisi leur carte';
						break;
					case VOTE_PHASE:
						$vars['turn']['phase']['title'] = 'Phase de vote';
						$vars['turn']['phase']['infos'] = 'Attendez le résultat des votes';
						break;
					case POINTS_PHASE:
						$vars['turn']['phase']['title'] = 'Décompte des points';
						$vars['turn']['phase']['infos'] = 'Voici le calcul des points :';
						break;
				}
			}
			else {
				switch($phase) {
					case STORYTELLER_PHASE:
						$vars['turn']['phase']['title'] = 'Tour du conteur';
						$vars['turn']['phase']['infos'] = 'Attendez que le conteur ait choisi sa carte';
						break;
					case BOARD_PHASE:
						$vars['turn']['phase']['title'] = 'Tour des joueurs';
						$vars['turn']['phase']['infos'] = ($actionStatus == ACTION_IN_PROGRESS) ? 'Sélectionnez une carte de votre main qui correspond au mieux à la description du conteur' : 'Attendez que tous les joueurs ait choisi une carte';
						break;
					case VOTE_PHASE:
						$vars['turn']['phase']['title'] = 'Phase de vote';
						$vars['turn']['phase']['infos'] = ($actionStatus == ACTION_IN_PROGRESS) ? 'Votez pour la carte que vous pensez être celle du conteur !' : 'Attendez que tous les joueurs ait fini de voter';
						break;
					case POINTS_PHASE :
						$vars['turn']['phase']['title'] = 'Décompte des points';
						$vars['turn']['phase']['infos'] = 'Voici le calcul des points :';
						break;
				}
			}
			$vars['turn']['phase']['id'] = $phase;
			$vars['storyteller'] = $storyteller;
			$vars['actionStatus'] = $actionStatus;
			debug($vars);
			render('play', $vars);
		}
	}
}

function _getActualGamePhase($gameID, $turnID) {
	$nbCards = count(getCardsInBoard($turnID));
	$nbPlayers = count(getPlayersInGame($gameID));
	if($nbCards == 0) {
		return STORYTELLER_PHASE;
	}
	else if($nbCards < $nbPlayers) {
		return BOARD_PHASE;
	}
	else if(count(getTurnsVote($turnID)) == ($nbPlayers - 1))
		return POINTS_PHASE;
	else
		return VOTE_PHASE;
}

//Fonction permettant de tester si un utilisateur a joué ou pas dans une phase considérée.
function _checkAction($phase, $playerID, $gameID, $turnID) {
	$action;

	switch($phase) {
		case BOARD_PHASE:
			//On tente de récupérer la carte que le joueur a joué pour ce tour. Si le résultat est un tableau c'est qu'une carte à été posée et donc que le joueur a joué pour cette phase
			$action = is_array(getPlayerCardInBoard($gameID, $playerID));
			break;
		case VOTE_PHASE:
			//Si l'id du joueur se trouve dans le tableau des joueurs ayant déjà voté alors l'action est effectuée
			if(!in_array($playerID, getSpecificArrayValues(getTurnsVote($turnID), 'us_id'))) {
				$action = false;
				//Si dans le tableau c'est que le joueur est le storyteller et donc qu'il n'a pas a voter
				if(in_array($playerID, getSpecificArrayValues(getTurnInfos($turnID, array('us_id')), 'us_id')))
					$action = true;
			}
			else
				$action = true;
			break;
		case STORYTELLER_PHASE:
			$action = false;
			break;
		case POINTS_PHASE:
			$action = true;
			break;
	}

	return $action ? ACTION_DONE : ACTION_IN_PROGRESS;
}

//Permet d'afficher le tableau des cartes en fonction de la phase. Si la phase est BOARD_PHASE alors les cartes apparaissent face cachées et si c'est la VOTE_PHASE elles apparaissent face visible avec la possibilité de voter
function _displayBoard($phase, $gameID, $turnID, $storyteller, $actionStatus) {
	$cardsIDs = getSpecificArrayValues(getCardsInBoard($turnID),'ca_id');
	$cards = array();

	foreach($cardsIDs as $cardID) {
		$cards[] = getCardInfos($cardID);
	}

	if($phase == BOARD_PHASE) {
		//récupération de la carte du joueur
		foreach($cards as $card) {
			echo '<img src="' . IMG_DIR . 'cards/back.jpg" alt="card back" title="Carte face cachée"/>';
			echo "\n";
		}
	}
	else if($phase == VOTE_PHASE) {
		//$userCardID = getOneRowResult(getPlayerCardInBoard($gameID, $_SESSION[USER_MODEL][USER_PK]), 'ca_id');
		shuffle($cards);
		if($actionStatus == ACTION_IN_PROGRESS && !$storyteller) {
			echo '<form method="post" action="' . BASE_URL . 'cards/vote">';
			foreach($cards as $card) {
				echo '<label for="' . $card['ca_id'] . '"><img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" /></label><input type="radio" id="' . $card['ca_id'] .'" name="cardID" value="' . $card['ca_id'] . '" />';
			}
				echo '<input type="hidden" name="gameID" value="' . $gameID . '" />';
				echo '<input type="hidden" name="turnID" value="' . $turnID . '" />';
				echo '<input type="submit" value="voter" />';
			echo '</form>';
		}
		else {
			foreach($cards as $card) {
				echo '<img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" />';
			}
		}
	}
	else if($phase == STORYTELLER_PHASE){
		echo 'Le conteur n\'a pas encore choisi sa carte';
	}
	else {
		echo 'décompte des points';
	}
}

function _displayHand($phase, $userID, $gameID, $turnID, $storyteller, $actionStatus) {
	$hand = getCardsInHand($userID, $turnID);

	switch($phase) {
		case STORYTELLER_PHASE:
			if($storyteller) {
				echo '<form method="post" action="' . BASE_URL . 'cards/addStorytellerCard">';
				echo '<label for="comment">Indice : </label><input type="text" name="comment" id="comment" />';
				foreach($hand as $card) {
					echo '<label for="' . $card['ca_id'] . '"><img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" /></label><input type="radio" id="' . $card['ca_id'] .'" name="cardID" value="' . $card['ca_id'] . '" />';
				}	
					echo '<input type="hidden" name="gameID" value="' . $gameID . '" />';
					echo '<input type="hidden" name="turnID" value="' . $turnID . '" />';
					echo '<input type="submit" value="Valider" />';
				echo '</form>';
			}
			else {
				foreach($hand as $card) {
					echo '<img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" />';
				}		
			}
			break;
		case BOARD_PHASE:
			foreach($hand as $card) {
				if(!$storyteller) {
					//die(var_dump($actionStatus));
					if($actionStatus == ACTION_IN_PROGRESS) 
						echo l('<img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" />', 'cards', 'addCard', array($gameID, $turnID, $card['ca_id']));
					else
						echo '<img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" />';
				}
				else {
					echo '<img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" />';
				}
			}
			break;
		case VOTE_PHASE:
			foreach($hand as $card) {
				echo '<img src="' . IMG_DIR . 'cards/' . $card['ca_image'] . '" alt="' . $card['ca_name'] . '" title="' . $card['ca_name'] . '" />';
			}
			break;
		case POINTS_PHASE:
			echo 'test';
			break;
	}
}