<?php
$sprintDir = dirname(__FILE__);
require_once "$sprintDir/../../../lib/share.php";


$config = loadPropertiesFromArgs();

$dsn = "pgsql:host={$config['db.host']};port={$config['db.port']};dbname={$config['db.name']}" ;
$pdo = new PDO($dsn, $config['db.adminuser'], $config['db.adminuser.pw']) ;

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION) ;

// On récupère le nom de la table des observations.
$sth = $pdo->query("SELECT table_name FROM metadata.table_format") ;
$tableNames = $sth->fetchAll() ;

// Begin transaction
$pdo->beginTransaction() ;

try {

	// Création de la fonction de recalcul de sensibilité pour la migration 
	$pdo->exec("
		CREATE OR REPLACE FUNCTION raw_data.sensitive_v11()
		RETURNS trigger AS
		\$BODY\$

		DECLARE
			rule_codage integer;
		BEGIN					
			-- Does the data deals with sensitive taxon for the departement and is under the sensitive duration ?
			SELECT especesensible.codage INTO rule_codage
			FROM referentiels.especesensible
			LEFT JOIN referentiels.especesensiblelistes ON especesensiblelistes.cd_sl = especesensible.cd_sl
			WHERE 
				(CD_NOM = NEW.cdNom
				OR CD_NOM = NEW.cdRef
				OR CD_NOM = ANY (
					WITH RECURSIVE node_list( code, parent_code, lb_name, vernacular_name) AS (
						SELECT code, parent_code, lb_name, vernacular_name
						FROM metadata.mode_taxref
						WHERE code = NEW.cdnom
				
						UNION ALL
				
						SELECT parent.code, parent.parent_code, parent.lb_name, parent.vernacular_name
						FROM node_list, metadata.mode_taxref parent
						WHERE node_list.parent_code = parent.code
						AND node_list.parent_code != '349525'
						)
					SELECT parent_code
					FROM node_list
					ORDER BY code
					)
				)
				AND CD_DEPT = ANY (NEW.codedepartementcalcule)
				AND (DUREE IS NULL OR (NEW.jourdatefin::date + DUREE * '1 year'::INTERVAL > now()))
				AND (NEW.occstatutbiologique IS NULL OR NEW.occstatutbiologique IN ( '0', '1', '2') OR cd_occ_statut_biologique IS NULL OR NEW.occstatutbiologique = CAST(cd_occ_statut_biologique AS text))
			
			--  Quand on a plusieurs règles applicables il faut choisir en priorité
			--  Les règles avec le codage le plus fort
			--  Parmi elles, la règle sans commentaire (rule_autre is null)
			--  Voir #579
			ORDER BY codage DESC, autre DESC
			--  on prend la première règle, maintenant qu'elles ont été ordonnées
			LIMIT 1;
				
				
			-- No rules found, the obs is not sensitive
			IF NOT FOUND THEN
				RETURN NEW;
			End if;
				
			-- A rule has been found, the obs is sensitive
			-- If sensitivity is different from previous sensitivity, we compute it again.
			If (rule_codage IS DISTINCT FROM OLD.sensiniveau) Then
				RETURN raw_data.sensitive_automatic();
			Else
				RETURN NEW;
			End if;
			
		END;
		\$BODY\$
			LANGUAGE plpgsql VOLATILE
			COST 100;
	");


	
	foreach ($tableNames as $index => $value) {

		$tableName = $value['table_name'] ;

		// Création d'un index sur cdNom pour accélerer les traitements.
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_$tableName_cdnom ON $tableName(cdnom)") ;

		// Désactivation temporaire des triggers de sensibilité.
		$pdo->exec("ALTER TABLE raw_data.$tableName DISABLE TRIGGER sensitive_automatic$tableName") ;
		$pdo->exec("ALTER TABLE raw_data.$tableName DISABLE TRIGGER sensitive_manual$tableName") ;

		// Création d'un trigger temporaire pour le calcul de sensibilité lors de la migration.
		$pdo->exec("
			CREATE TRIGGER sensitive_v11$tableName 
			BEFORE UPDATE OF cdnom, cdref
			ON raw_data.$tableName 
			FOR EACH ROW EXECUTE PROCEDURE sensitive_v11()
		");

		
		// Cas A) Lorsque TYPE_CHANGE=MODIFICATION et CHAMP=CD_REF, il faut : trouver les données telles que cdRef valent VALEUR_INIT. Pour ces données, mettre :
		//	¤ cdNomCalcule mis à jour
		//  ¤ cdRefCalcule=VALEUR_FINAL
		//	¤ TaxoStatut=Diffusé
		//	¤ TaxoModif=Modification TAXREF
		//	¤ TaxoAlerte=NON
		//  ¤ nomValide mis à jour
		$casA = $pdo->prepare("UPDATE raw_data.$tableName SET
				cdnomcalcule = :cdNom::varchar,
				cdrefcalcule = :valeurFinal,
				nomvalide = (SELECT nom_complet FROM referentiels.taxref WHERE cd_nom = :cdNom),
				taxostatut = '0',
				taxomodif = '0',
				taxoalerte = '1'
			WHERE cdnom = :cdNom
		");

		// Même chose quand seul le cdRef est fourni et le cdnom absent.
		$casAbis = $pdo->prepare("UPDATE raw_data.$tableName SET
				cdrefcalcule = :valeurFinal,
				taxostatut = '0',
				taxomodif = '0',
				taxoalerte = '1'
			WHERE cdref = :valeurInit
			AND cdnom IS NULL
		");

		// Cas B0) (traitement par défaut du cas B, si ni B1, ni B2 et ni B3) 
		// Lorsque TYPE_CHANGE=RETRAIT (on a alors que des CHAMP=CD_NOM), il faut : trouver les données telles que cdNom vaut VALEUR_INIT.
		// Pour ces données, mettre :
		// ¤ cdNomCalcule à NULL
		// ¤ cdRef_Calcule à NULL
		// ¤ nomValide à NULL
		// ¤ TaxoStatut =‘Gel’
		// ¤ TaxoModif = ‘Gel TAXREF’
		// ¤ taxoAlerte = OUI.
		$casB0 = $pdo->prepare("UPDATE raw_data.$tableName SET
				cdnomcalcule = NULL,
				cdrefcalcule = NULL,
				nomvalide = NULL,
				taxostatut = '1',
				taxomodif = '1',
				taxoalerte = '0'
			WHERE cdnom = :valeurInit
		");

		// Cas B1) Lorsque TYPE_CHANGE=RETRAIT (on a alors que des CHAMP=CD_NOM), il faut : trouver les données telles que cdNom vaut VALEUR_INIT
		// et CD_NOM correspond à un CD_NOM de CDNOM_DISPARUS et que CD_RAISON_SUPPRESSION = 1, auquel cas il faut mettre :
		// ¤ cdNomCalcule=CD_NOM_REMPLACEMENT
		// ¤ mettre à jour cdRefCalcule et nomValide à partir du nouveau cdNomCalcule.
		// ¤ TaxoStatut=Diffusé
		// ¤ TaxoModif=Modification TAXREF
		// ¤ TaxoAlerte=NON
		$casB1 = $pdo->prepare("UPDATE raw_data.$tableName SET
				cdnomcalcule = :cdNomRemplacement::varchar,
				cdrefcalcule = :cdNomRemplacement,
				nomValide = (SELECT nom_complet FROM referentiels.taxref WHERE cd_nom = :cdNomRemplacement),
				taxostatut = '0',
				taxomodif = '0',
				taxoalerte = '1'
			WHERE cdnom = :valeurInit
		");     

		// Cas B2) Lorsque TYPE_CHANGE=RETRAIT (on a alors que des CHAMP=CD_NOM), il faut : trouver les données telles que cdNom vaut VALEUR_INIT
		// et CD_NOM correspond à un CD_NOM de CDNOM_DISPARUS et que CD_RAISON_SUPPRESSION = 3, auquel cas il faut mettre :
		// ¤ cdNomCalcule à NULL
		// ¤ cdRef_Calcule à NULL
		// ¤ nomValide à NULL
		// ¤ TaxoStatut =‘Retrait’ ???
		// ¤ TaxoModif = ‘Suppression TAXREF’
		// ¤ taxoAlerte = OUI.
		$casB2 = $pdo->prepare("UPDATE raw_data.$tableName SET
				cdnomcalcule = NULL,
				cdrefcalcule = NULL,
				nomvalide = NULL,
				taxostatut = '1',
				taxomodif = '2',
				taxoalerte = '0'
			WHERE cdnom = :valeurInit
		");

		// Cas B3) Lorsque TYPE_CHANGE=RETRAIT (on a alors que des CHAMP=CD_NOM), il faut : trouver les données telles que cdNom vaut VALEUR_INIT
		// et CD_NOM correspond à un CD_NOM de CDNOM_DISPARUS et que CD_RAISON_SUPPRESSION = 2, auquel cas il faut mettre :
		// ¤ cdNomCalcule à NULL
		// ¤ cdRef_Calcule à NULL
		// ¤ nomValide à NULL
		// ¤ TaxoStatut =‘Retrait’
		// ¤ TaxoModif = 'Gel TAXREF’
		// ¤ taxoAlerte = OUI.
		$casB3 = $pdo->prepare("UPDATE raw_data.$tableName SET
				cdnomcalcule = NULL,
				cdrefcalcule = NULL,
				nomValide = NULL,
				taxostatut = '1',
				taxomodif = '1',
				taxoalerte = '0'
			WHERE cdnom = :valeurInit
		");


		// Cas C) Lorsque TYPE_CHANGE=MODIFICATION et CHAMP=LB_NOM, il faut : trouver les données telles que cdNom valent CD_NOM. Pour ces données, mettre :
		// ¤ nomValid=VALEUR_FINAL.
		$casC = $pdo->prepare("UPDATE raw_data.$tableName SET
				nomvalide = (SELECT nom_complet FROM referentiels.taxref WHERE cd_nom = :cdNom)
			WHERE cdnom = :cdNom
		");

		$file = new SplFileObject(realpath(dirname(__FILE__).DIRECTORY_SEPARATOR.'all_changes.csv'), 'r') ;
		$file->setFlags(SplFileObject::READ_CSV) ;
		$header = $file->fgetcsv() ;
		while ($row = $file->fgetcsv()) {

			if (empty($row) || count($row) != count($header)) {
				continue ;
			}

			$data = array_combine($header, $row) ;

			$cdNom = $data['cd_nom'] ;
			$typeChange = $data['type_change'] ;
			$champ = $data['champ'] ;
			$cdRaisonSuppression = $data['cd_raison_suppression'] ;
			$valeurInit = $data['valeur_init'] ;
			$valeurFinal = $data['valeur_final'] ;
			$cdNomRemplacement = $data['cd_nom_remplacement'] ;

			// Cas A		
			if ('MODIFICATION' == $typeChange && 'CD_REF' == $champ) {	
				$casA->execute(array(
					'cdNom' => $cdNom,
					'valeurFinal' => $valeurFinal
				));
				$casAbis->execute(array(
					'valeurInit' => $valeurInit,
					'valeurFinal' => $valeurFinal
				));
			}

			// Cas B
			if ('RETRAIT' == $typeChange) {

				if (!empty($cdRaisonSuppression) && '1' == $cdRaisonSuppression) {
					$casB1->execute(array(
						'cdNomRemplacement' => $cdNomRemplacement,
						'valeurInit' => $valeurInit
					));
				} else if (!empty($cdRaisonSuppression) && '3' == $cdRaisonSuppression) {
					$casB2->execute(array(
						'valeurInit' => $valeurInit
					));
				} else if (!empty($cdRaisonSuppression) && '2' == $cdRaisonSuppression) {
					$casB3->execute(array(
						'valeurInit' => $valeurInit
					)) ;
				} else {
					// Cas B0, par défaut
					$casB0->execute(array(
						'valeurInit' => $valeurInit
					));
				}
			}

			// Cas C
			if ('MODIFICATION' == $typeChange && 'LB_NOM' == $champ) {
				$casC->execute(array(
					'cdNom' => $valeurFinal
				));
			}

		}


		// Suppression du trigger temporaire
		$pdo->exec("DROP TRIGGER sensitive_v11$tableName ON raw_data.$tableName") ;

		// Réactivation des triggers de sensibilité.
		$pdo->exec("ALTER TABLE raw_data.$tableName ENABLE TRIGGER sensitive_automatic$tableName") ;
		$pdo->exec("ALTER TABLE raw_data.$tableName ENABLE TRIGGER sensitive_manual$tableName") ;
	}

	// Suppression de la fonction de calcul de sensibilité temporaire
	$pdo->exec("DROP FUNCTION raw_data.sensitive_v11()") ;
	
    
} catch (PDOException $e) {
	
	$pdo->rollback() ;
	
	echo "$sprintDir/update_db_sprint.php\n";
	echo "exception: " . $e->getMessage() . "\n";
	exit(1);
}


// Commit transaction
$pdo->commit() ;



