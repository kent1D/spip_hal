<?php

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

// prend un json issu de la recherche de HAL et retourne un tableau des documents lus,
// et false en cas d'erreur
function analyser_publications($json, $url_syndic = '') {
	$dede = 0;
	try {
		$json = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		$json =	[];
		spip_log('Failed to parse Json data : ' . $e->getMessage(), _LOG_ERREUR);
	}
	$json = pipeline('pre_syndication_publications', $json);
	$publications  = false;
	if (isset($json['response']) and isset($json['response']['docs']) and is_array($json['response']['docs'])) {
		$publications = [];
		$count = 0;
		foreach ($json['response']['docs'] as $id => $contenu_publication) {
			$count++;
			$infos_publication = [];
			$affiche = false;
			/**
			 * Correspondance des items du json par rapport aux champs de la base de donnée
			 */
			$champs = [
				'docid' => 'docid',
				'language_s' => 'lang',
				'title_s' => 'titre',
				'subTitle_s' => 'soustitre',
				'hal_id' => 'identifiant',
				'uri_s' => 'url_publication',
				'page_s' => 'page',
				'docType_s' => 'typdoc',
				'publisher_s' => 'editeur',
				'writingDate_s' => 'date_ecriture',
				'submittedDate_s' => 'date_soumission',
				'producedDate_s' => 'date_production',
				'modifiedDate_s' => 'date_modif',
				'citationRef_s' => 'citation_reference',
				'citationFull_s' => 'citation_complete',
				'authFullName_s' => 'lesauteurs',
				'bookTitle_s' => 'livre',
				'journalTitle_s' => 'revue',
				'journalId_i' => 'revue_id',
				'journalDate_s' => 'date_revue',
				'abstract_s' => 'resume',
				'comment_s' => 'commentaire',
				'issue_s' => 'issue',
				'keyword_s' => 'tags'
			];
			foreach ($champs as $champ => $base) {
				if (isset($contenu_publication[$champ]) and (is_array($contenu_publication[$champ]) or (strlen($contenu_publication[$champ]) > 0))) {
					switch ($champ) {
						case 'keyword_s':
						case 'publisher_s':
							$infos_publication[$base] = implode(', ', $contenu_publication[$champ]);
							break;
						case 'producedDate_s':
						case 'writingDate_s':
							if (strlen($contenu_publication[$champ]) == 4 and preg_match('/\d{4}/', $contenu_publication[$champ])) {
								$infos_publication[$base] = $contenu_publication[$champ] . '-01-01 00:00:00';
								$infos_publication['date_production_format'] = 'annee';
							}elseif (strlen($contenu_publication[$champ]) == 7 and preg_match('/\d{4}-\d{2}/', $contenu_publication[$champ])) {
								$infos_publication[$base] = $contenu_publication[$champ] . '-01 00:00:00';
								$infos_publication['date_production_format'] = 'mois';
							}elseif (strlen($contenu_publication[$champ]) == 10 and preg_match('/\d{4}-\d{2}-\d{2}/', $contenu_publication[$champ])) {
								$infos_publication[$base] = $contenu_publication[$champ] . ' 00:00:00';
								$infos_publication['date_production_format'] = 'jour';
							}elseif (strlen($contenu_publication[$champ]) == 19) {
								$infos_publication[$base] = $contenu_publication[$champ];
								$infos_publication['date_production_format'] = 'complet';
							}
							break;
						case 'journalDate_s':
						case 'submittedDate_s':
						case 'modifiedDate_s':
							if (strlen($contenu_publication[$champ]) == 4 and preg_match('/\d{4}/', $contenu_publication[$champ])) {
								$infos_publication[$base] = $contenu_publication[$champ] . '-01-01 00:00:00';
							}elseif (strlen($contenu_publication[$champ]) == 7 and preg_match('/\d{4}-\d{2}/', $contenu_publication[$champ])) {
								$infos_publication[$base] = $contenu_publication[$champ] . '-01 00:00:00';
							}elseif (strlen($contenu_publication[$champ]) == 10 and preg_match('/\d{4}-\d{2}-\d{2}/', $contenu_publication[$champ])) {
								$infos_publication[$base] = $contenu_publication[$champ] . ' 00:00:00';
							}elseif (strlen($contenu_publication[$champ]) == 19) {
								$infos_publication[$base] = $contenu_publication[$champ];
							}
							break;
						case 'language_s':
							$infos_publication[$base] = $contenu_publication[$champ][0];
							break;
						case 'authFullName_s':
							$infos_publication[$base] = implode(', ', $contenu_publication[$champ]);
							break;
						default:
							if (is_array($contenu_publication[$champ])) {
								if (count($contenu_publication[$champ]) == 1) {
									$infos_publication[$base] = $contenu_publication[$champ][0];
								} else {
									$infos_publication[$base] = '<multi>';
									foreach ($contenu_publication[$champ] as $key => $content) {
										if (isset($contenu_publication['language_s'][$key])) {
											$infos_publication[$base] .= '[' . $contenu_publication['language_s'][$key] . ']' . $content;
										}
									}
									$infos_publication[$base] .= '</multi>';
								}
							}else {
								$infos_publication[$base] = $contenu_publication[$champ];
							}
							break;
					}
				}
			}
			/**
			 * On va chercher les ISBNs dans les citations
			 */
			foreach (['citation_reference','citation_complete'] as $info) {
				if (isset($infos_publication[$info]) and (strlen($infos_publication[$info]) > 0)) {
					if (preg_match('/ISBN ([0-9\-]{10,17}) /Uims', $infos_publication[$info], $matches)) {
						$infos_publication['isbn'] = $matches[1];
						continue;
					}
				}
			}
			/**
			 * On conserve l'ensemble des infos du document au cas où quand même
			 */
			$infos_publication['hal_complet'] = serialize($contenu_publication);

			$publications[] = $infos_publication;
		}
	}
	return $publications;
}
