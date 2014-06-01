<?php

if (!defined("_ECRIRE_INC_VERSION")) return;

## valeurs modifiables dans mes_options
## attention il est tres mal vu de prendre une periode < 20 minutes
if (!defined('_PERIODE_SYNDICATION_HAL'))
	define('_PERIODE_SYNDICATION_HAL', 2*60);
if (!defined('_PERIODE_SYNDICATION_SUSPENDUE_HAL'))
	define('_PERIODE_SYNDICATION_SUSPENDUE_HAL', 24*60);

include_spip('inc/hal');

// http://doc.spip.org/@genie_syndic_dist
function genie_hal_dist($t) {
	spip_log('genie_hal_dist','test.'._LOG_ERREUR);
	return executer_une_syndication_hal();
}

//
// Effectuer la syndication d'un unique site,
// retourne 0 si aucun a faire ou echec lors de la tentative
//
function executer_une_syndication_hal() {
	spip_log('executer_une_syndication_hal','test.'._LOG_ERREUR);

	// Et un site 'oui' de plus de 2 heures, qui passe en 'sus' s'il echoue
	$where = "NOT(" . sql_date_proche('date_syndic', (0 - _PERIODE_SYNDICATION_HAL) , "MINUTE") . ')';
	$id_hal = sql_getfetsel("id_hal", "spip_hals", $where, '', "date_syndic", "1");
	spip_log($where,'test.'._LOG_ERREUR);
	spip_log($id_hal,'test.'._LOG_ERREUR);
	if ($id_hal) {
		// inserer la tache dans la file, avec controle d'unicite
		job_queue_add('hal_a_jour','hal_a_jour',array($id_hal),'genie/hal',true);
	}

	return 0;
}


/**
 * Mettre a jour le dépot
 * Attention, cette fonction ne doit pas etre appellee simultanement
 * sur un meme site: un verrouillage a du etre pose en amont.
 * => elle doit toujours etre appelee par job_queue_add
 *
 * @param int $now_id_hal
 * 	Identifiant numérique du dépot
 * @return bool|string
 */
function hal_a_jour($now_id_hal) {
	spip_log('hal a jour '.$now_id_hal,'test.'._LOG_ERREUR);
	
	$call = debug_backtrace();
	if ($call[1]['function']!=='queue_start_job')
		spip_log("hal_a_jour doit etre appelee par JobQueue Cf. http://trac.rezo.net/trac/spip/changeset/10294",_LOG_ERREUR);
	
	$row = sql_fetsel("*", "spip_hals", "id_hal=".intval($now_id_hal));
	
	spip_log($row,'test.'._LOG_ERREUR);
	if (!$row) return false;
	
	$url_syndic = $row['url_syndic'];
	
	if ($row['moderation'] == 'oui')
		$moderation = 'dispo';	// a valider
	else
		$moderation = 'publie';	// en ligne sans validation

	// Aller chercher les donnees du JSON et les analyser
	include_spip('inc/distant');
	spip_log($url_syndic,'test.'._LOG_ERREUR);
	
	$json = recuperer_page($url_syndic, true);
	spip_log($json,'test.'._LOG_ERREUR);
	
	if (!$json)
		$publications = _T('hal:avis_echec_recuperation');
	else
		$publications = analyser_publications($json, $url_syndic);

	spip_log($publications,'test.'._LOG_ERREUR);
	
	// Renvoyer l'erreur le cas echeant
	if (!is_array($publications)) return false;

	// Les enregistrer dans la base

	$faits = array();
	foreach ($publications as $data) {
		inserer_publication_hal($data, $now_id_hal, $moderation, $url_syndic, $faits);
	}
	spip_log($faits,'test.'._LOG_ERREUR);
	return false; # c'est bon
}


//
// Insere une publication (renvoie true si l'article est nouveau)
// en  verifiant qu'on ne vient pas de l'ecrire avec
// un autre item du meme feed qui aurait le meme link
//
function inserer_publication_hal ($data, $now_id_hal, $statut, $url_syndic, &$faits) {
	spip_log('inserer_publication_hal','test.'._LOG_ERREUR);
	// Creer le lien s'il est nouveau - cle=(id_hal,url)
	// On coupe a 255 caracteres pour eviter tout doublon
	// sur une URL de plus de 255 qui exloserait la base de donnees
	$le_lien = substr($data['url'], 0,255);

	// si true, un lien deja syndique arrivant par une autre source est ignore
	// par defaut [false], chaque source a sa liste de liens, eventuellement
	// les memes
	define('_SYNDICATION_URL_UNIQUE', false);

	// Si false, on ne met pas a jour un lien deja syndique avec ses nouvelles
	// donnees ; par defaut [true] : on met a jour si le contenu a change
	// Attention si on modifie a la main un article syndique, les modifs sont
	// ecrasees lors de la syndication suivante
	define('_SYNDICATION_CORRECTION', true);

	// Chercher les liens de meme cle
	// S'il y a plusieurs liens qui repondent, il faut choisir le plus proche
	// (ie meme titre et pas deja fait), le mettre a jour et ignorer les autres
	$n = 0;
	$s = sql_select("id_hals_publication,titre,id_hal,statut", "spip_hals_publications",
		"url=" . sql_quote($le_lien)
		. (_SYNDICATION_URL_UNIQUE
			? ''
			: " AND id_hal=".intval($now_id_hal))
		." AND " . sql_in('id_hals_publication', $faits, 'NOT'), "", "maj DESC");
	while ($a = sql_fetch($s)) {
		$id =  $a['id_hals_publication'];
		$id_hal = $a['id_hal'];
		if ($a['titre'] == $data['titre']) {
			$id_hals_publication = $id;
			break;
		}
		$n++;
	}
	// S'il y en avait qu'un, le prendre quel que soit le titre
	if ($n == 1)
		$id_hals_publication = $id;
	// Si l'article n'existe pas, on le cree
	elseif (!isset($id_hals_publication)) {
		$champs = array(
			'id_hal' => $now_id_hal,
			'url' => $le_lien,
			'date' => date("Y-m-d H:i:s", $data['date_modif'] ? $data['date_modif'] : $data['date_soumission']),
			'statut'  => $statut
		);
		// Envoyer aux plugins
		$champs = pipeline('pre_insertion',
			array(
				'args' => array(
					'table' => 'spip_hals_publications',
				),
				'data' => $champs
			)
		);
		$ajout = $id_hals_publication = sql_insertq('spip_hals_publications', $champs);
		if (!$ajout) return;
		pipeline('post_insertion',
			array(
				'args' => array(
					'table' => 'spip_hals_publications',
					'id_objet' => $id_hals_publication
				),
				'data' => $champs
			)
		);
	}
	$faits[] = $id_hals_publication;

	// Si le lien n'est pas nouveau, plusieurs options :
	if (!$ajout) {
		// 1. Lien existant : on corrige ou pas ?
		if (!_SYNDICATION_CORRECTION) {
			return;
		}
		// 2. Le lien existait deja, lie a un autre hal
		if (_SYNDICATION_URL_UNIQUE AND $id_hal != $now_id_hal)
			return;
	}

	// Mise a jour du contenu (titre,auteurs,description,date?,source...)
	$vals = array(
			'docid' => $data['docid'],
			'titre' => $data['titre'],
			'identifiant' => $data['identifiant'],
			'typdoc' => $data['typdoc'],
			'date_soumission' => $data['date_soumission'],
			'date_production' => $data['date_production'],
			'date_modif' => $data['date_modif'],
			'citation_reference' => $data['citation_reference'],
			'citation_complete' => $data['citation_complete'],
			'page' => $data['page'],
			//'format' => $data['format'],
			'hal_complet' => $data['hal_complet'],
			'lang'=> substr($data['lang'],0,10));

	sql_updateq('spip_hals_publications', $vals, "id_hals_publication=".intval($id_hals_publication));

	// Point d'entree post_syndication
	pipeline('post_syndication',
		array(
			'args' => array(
				'table' => 'spip_hals_publications',
				'id_objet' => $id_hals_publication,
				'url' => $le_lien,
				'id_hal' => $now_id_hal,
				'ajout' => $ajout,
			),
			'data' => $data
		)
	);

	return $ajout;
}

?>