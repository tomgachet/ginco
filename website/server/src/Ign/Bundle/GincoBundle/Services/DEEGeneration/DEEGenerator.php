<?php
namespace Ign\Bundle\GincoBundle\Services\DEEGeneration;

use Ign\Bundle\GincoBundle\Exception\DEEException;
use Ign\Bundle\OGAMBundle\Entity\Generic\QueryForm;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Ign\Bundle\OGAMBundle\Services\ConfigurationManager;
use Symfony\Bridge\Monolog\Logger;

/**
 * Class DEEGenerator
 * Responsible of the GML export of the DEE
 *
 * @package Ign\Bundle\GincoBundle\Services\DEEGeneration
 */
class DEEGenerator {

	protected $twig; 

	/**
	 * @var Logger
	 */
	protected $logger;


	/**
	 * @var ConfigurationManager
	 */
	protected $configuration;

	/**
	 * The models.
	 *
	 * @var EntityManager
	 */
	protected $em;

	protected $genericService;

	protected $queryService;
	
	// Class DEEModel
	protected $dee;

	// counter used to construct file-unique gml ids
	protected $gmlId;

	/**
	 * DEEGenerator constructor.
	 * @param $logger
	 * @param $locale
	 * @param $configuration
	 * @param $em
	 * @param $emm
	 * @param $genericService
	 * @param $queryService
	 */
	public function __construct($em, $configuration, $genericService, $queryService, $logger) {

		$this->em = $em;
		$this->configuration = $configuration;
		$this->genericService = $genericService;
		$this->queryService = $queryService;

		// Instantiate a twig environment (independant from the one which renders the site)
		$loader = new \Twig_Loader_Filesystem(__DIR__ . '/templates');
        $this->twig = new \Twig_Environment($loader, array(
            // 'cache' => __DIR__ . '/templates/cache',
            'cache' => false,
            'debug' => true,
        ));

		// Instantiate DEEModel
 		$this->dee = new DEEModel();
		
		// gmlId is generated as a sequence of integer
		$this->gmlId = 1;
	}

	/**
	 * Create the GML of the DEE for jdd with the id given.
	 * Write it in file $fileName.
	 * 
	 * Optionally, a message id can be passed to the function, in which case
	 * the function reports its progress percentage into the message table
	 *
	 * Principle:
	 * - create 4 intermediate files :
	 * - header and end of the gml file (independent from the submission)
	 * - observations
	 * - groups (computed from observations)
	 * - concatenate the intermediate files and delete them
	 *
	 *
	 * @param $jddId
	 * @param $fileName name and path of the gml file to write
	 * @param null $messageId
	 * @param null $userLogin
	 * @return bool
	 * @throws DEEException
	 */
	public function generateDeeGml($jddId, $fileName, $messageId = null, $userLogin = null) {
		// Configure memory and time limit because the program ask a lot of resources
		ini_set("memory_limit", $this->configuration->getConfig('memory_limit', '1024M'));
		ini_set("max_execution_time", 0);
		
		// Get the jdd and the validated (published) submissions in the jdd
		$jdd = $this->em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);
		if (!$jdd) {
			throw new DEEException("Jdd not found for that id: $jddId");
		}
		$submissions = $jdd->getValidatedSubmissions();
		if (count($submissions) == 0 ) {
			return false;
		}
		$submissionsIds = [];
		foreach ($submissions as $submission) {
			$submissionsIds[] = $submission->getId();
		}
		// Get the data model
		$model = $jdd->getModel();


		// -- Create a query object : the query must find all lines with given submission_ids
		// And print a list of all fields in the model
		$queryForm = new QueryForm();

		// Get all table fields for model
		$tableFields = $this->em->getRepository('OGAMBundle:Metadata\TableField')->getTableFieldsForModel($model->getId());
		
		// Get all Form Fields for Model
		$formFields = $this->em->getRepository('OGAMBundle:Metadata\FormField')->getFormFieldsFromModel($model->getId());

		// -- Criteria fields for the query : we only add SUBMISSION_ID
		// -- Result fields for the query : all fields of the model
		foreach ($formFields as $formField) {
			$data = $formField->getData()->getData();
			$format = $formField->getFormat()->getFormat();

			// Search criteria
			switch ($data) {
				case 'SUBMISSION_ID':
					// Add submissions ids as an array, it will result in a x OR y OR z
					$queryForm->addCriterion($format, 'SUBMISSION_ID', $submissionsIds);
					break;
			}
			// Result columns
			$queryForm->addColumn($format, $data);
		}

		// -- Generate the SQL Request
		
		// To generate unique ids for the gml, we put a marker wich is replaced by an id afterwards
		$gml_id = "'ID#GMLID#'"; // enclose it in simple quotes to be recognized as a string in ST_AsGML
		
		$options = array(
			'geometry_format' => 'gml',
			'geometry_srs' => 4326,
			'gml_precision' => 8,
			'gml_id' => $gml_id,
			'datetime_format' => 'YYYY-MM-DD"T"HH24:MI:SSTZ'
		);

		// Get the mappings for the query form fields
		$queryForm = $this->queryService->setQueryFormFieldsMappings($queryForm);
		$mappingSet = $queryForm->getFieldMappingSet($queryForm);
		
		//TODO : set the real user params
		$userInfos = [
			"providerId" => NULL,
			"DATA_QUERY_OTHER_PROVIDER" => true,
			"DATA_EDITION_OTHER_PROVIDER" => true
		];
		
		$select = $this->genericService->generateSQLSelectRequest('RAW_DATA', $queryForm->getColumns(), $mappingSet, $userInfos, $options);
		$from = $this->genericService->generateSQLFromRequest('RAW_DATA', $mappingSet);
		$where = $this->genericService->generateSQLWhereRequest('RAW_DATA', $queryForm->getCriteria(), $mappingSet, $userInfos);
		$sqlPKey = $this->genericService->generateSQLPrimaryKey('RAW_DATA', $mappingSet);
		$order = " ORDER BY " . $sqlPKey;
		$sql = $select . $from . $where . $order;
		
		// Count Results
		$total = $this->queryService->getQueryResultsCount($from, $where);
		
		// -- Export results to a GML file
		
		if ($total != 0) {
			
			// Opens a file for observations ($filename."_observations")
			$filePath = pathinfo($fileName, PATHINFO_DIRNAME);
			$pathExists = is_dir($filePath) || mkdir($filePath, 0755, true);
			if (!$pathExists) {
				throw new DEEException("Error: could not create directory: $filePath");
			}
			$fileNameObservations = $fileName . "_observations";
			$out = fopen($fileNameObservations, 'w');
			if (!$out) {
				throw new DEEException("Error: could not open (w) file: $fileName");
			}
			fclose($out);
			
			// (Re)start gmlId for each file
			$this->gmlId = 1;
			
			// array of groups (regroupements)
			$groups = array();
			
			// -- Write beginning and end of the GML
			$fileNameHeader = $fileName . "_header";
			$this->generateHeaderGML($fileNameHeader);
			
			$fileNameEnd = $fileName . "_end";
			$this->generateEndGML($fileNameEnd);
			
			// -- Batch execute the request, and write observations to the output file
			$batchLines = 1000;
			$batches = ceil($total / $batchLines);
			
			for ($i = 0; $i < $batches; $i ++) {
				
				$batchSQL = $sql . " LIMIT $batchLines OFFSET " . $i * $batchLines;
				
				// -- Execute query and put results in a formatted array of strings
				$results = $this->queryService->getQueryResults($batchSQL);
				
				// Put lines in a formatted array
				$resultsArray = array();
				foreach ($results as $line) {
					$resultLine = array();
					foreach ($tableFields as $tableField) {
						$key = strtolower($tableField->getName());
						$value = $line[$key];
						$data = $tableField->getData()->getData();

						if ($value == null) {
							$resultLine[$data] = '';
						} else {
							$type = $tableField->getData()->getUnit()->getType();
							switch ($type) {
								case 'ARRAY':
									// Just sanitize string (remove "", {}, and [])
									$bad = array("[", "]", "\"", "'", "{", "}");
									$value = str_replace($bad, "", $value);
									$resultLine[$data] = $value;
									break;
								
								case "CODE":
								default:
									// Default case : String or numeric value
									$resultLine[$data] = $value;
									break;
							}
						}
					}
					$resultsArray[] = $resultLine;
				}
				
				// Write the GML in the output file
				$params = array(
					'site_name' => $this->configuration->getConfig('site_name', 'Plateforme GINCO-SINP')
				);
				$this->generateObservationsGML($resultsArray, $fileNameObservations, $params, $messageId, $i * $batchLines, $total);
				
				// Generate Group of observations (identified by "identifiantregroupementpermanent")
				// for each batch step, complete the $groups array
				$groups = $this->dee->groupObservations($resultsArray, $groups, $params);
			}
			
			// -- Write the "groups" file
			$fileNameGroups = $fileName . "_groups";
			$this->generateGroupsGML($groups, $fileNameGroups);
			
			// -- Put the 4 intermediate files together and delete them
			system("cat $fileNameHeader $fileNameGroups $fileNameObservations $fileNameEnd > $fileName");
			unlink($fileNameHeader);
			unlink($fileNameGroups);
			unlink($fileNameObservations);
			unlink($fileNameEnd);
		}
		return $total != 0;
	}

	/**
	 * Generate the observations part of the gml for the DEE
	 * and write it to an output (intermediate) file.
	 * Write in "append" mode, so the file can be wited to by batches.
	 *
	 * @param $observations :
	 *        	batch of observations
	 * @param $outputFile :
	 *        	file name to write.
	 * @param array $params
	 *        	: associative id of parameters
	 * @param int|null $messageId
	 *        	: job id in the job_queue table. If not null, the function will write its progress in the job_queue table.
	 * @param $startLine :
	 *        	start line of the batch in the entire list of observations
	 * @param $total :
	 *        	total nb of the entire list of observations
	 * @throws DEEException
	 */
	protected function generateObservationsGML($observations, $outputFile, $params = null, $messageId= null, $startLine = 0, $total = 0) {
		// Open the file in append mode
		$out = fopen($outputFile, 'a');
		if (!$out) {
			throw new DEEException("Error: could not open (a) file: $outputFile");
		}
		
		// Write Observations
		foreach ($observations as $index => $observation) {
			$observation = $this->dee->formatDates($observation);
			$observation = $this->dee->transformCodes($observation);
			$observation = $this->dee->fillValues($observation, array(
				'orgtransformation' => (isset($params['site_name']) ? $params['site_name'] : 'Plateforme GINCO-SINP')
			));
			$observation = $this->dee->fillValues($observation, array(
				'deedatetransformation' => (date('c'))
			));
			$observation = $this->dee->specialCharsXML($observation);
			$observation = $this->dee->structureObservation($observation);
			
			fwrite($out, $this->strReplaceBySequence("#GMLID#", $this->generateObservation($observation)));
			
			// report progress every 1, 10, 100 or 1000 lines TODO
			if ($messageId) {
				$progress = $startLine + $index + 1;
				if (($total <= 100) || ($total <= 1000 && ($progress % 10 == 0)) || ($total <= 10000 && ($progress % 100 == 0)) || ($progress % 1000 == 0)) {
					$this->jm->setProgress($messageId, $progress);
					// uncomment to see process runnning.. slowly
					// sleep(1);
				}
			}
		}
		// Close
		fclose($out);
	}

	/**
	 * Generate the groups part of the gml for the DEE
	 * and write it to an output (intermediate) file.
	 *
	 * @param
	 *        	$groups
	 * @param
	 *        	$outputFile
	 * @throws DEEException
	 */
	protected function generateGroupsGML($groups, $outputFile) {
		// Open the file in write mode
		$out = fopen($outputFile, 'w');
		if (!$out) {
			throw new DEEException("Error: could not open (w) file: $outputFile");
		}
		// Write groups
		foreach ($groups as $group) {
			fwrite($out, $this->strReplaceBySequence("#GMLID#", $this->generateRegroupement($group)));
		}
		// Close
		fclose($out);
	}

	/**
	 * Generate the header part of the gml for the DEE
	 * and write it to an output (intermediate) file.
	 *
	 * @param
	 *        	$outputFile
	 * @throws DEEException
	 */
	protected function generateHeaderGML($outputFile) {
		// Open the file in write mode
		$out = fopen($outputFile, 'w');
		if (!$out) {
			throw new DEEException("Error: could not open (w) file: $outputFile");
		}
		// Write GML header
		fwrite($out, $this->strReplaceBySequence("#GMLID#", $this->twig->render('header.xml')));
		
		// Close
		fclose($out);
	}

	/**
	 * Generate the end part of the gml for the DEE
	 * and write it to an output (intermediate) file.
	 *
	 * @param
	 *        	$outputFile
	 * @throws DEEException
	 */
	protected function generateEndGML($outputFile) {
		// Open the file in write mode
		$out = fopen($outputFile, 'w');
		if (!$out) {
			throw new DEEException("Error: could not open (w) file: $outputFile");
		}
		// Write GML header
		fwrite($out, $this->twig->render('end.xml'));
		// Close
		fclose($out);
	}

	/**
	 * Generate a string of the GML for one group
	 *
	 * @param
	 *        	$group
	 * @return string
	 */
	protected function generateRegroupement($group) {
		$part = $this->twig->render('regroupement.xml.twig', array(
			'regroupement' => $group
		));
		return $part;
	}

	/**
	 * Generate a string of the GML for one observation
	 *
	 * @param
	 *        	$observation
	 * @return string
	 */
	protected function generateObservation($observation) {
		$part = $this->twig->render('sujet_observation.xml.twig', array(
			'observation' => $observation
		));
		return $part;
	}

	/**
	 * Replaces all occurences of the string $needle
	 * by a sequence of integers starting at $this->gmlId
	 * Ex : "#NEEDLE#, #NEEDLE# and #NEEDLE#" => "1, 2 and 3"
	 * http://codepad.org/BjVwEy8u
	 *
	 * @param string $needle        	
	 * @param string $str        	
	 * @return string
	 */
	protected function strReplaceBySequence($needle, $str) {
		while (($pos = strpos($str, $needle)) !== false) {
			$str = substr_replace($str, $this->gmlId, $pos, strlen($needle));
			$this->gmlId ++;
		}
		return $str;
	}

	/**
	 * Create a gzipped archive containing:
	 * - the dee gml file
	 * - informations on the jdd
	 * And put the archive in the "deePublicDirectory" from conf (in the public directory).
	 *
	 * @param
	 *        	$jddId
	 * @param
	 *        	$fileName
	 * @param
	 *        	$comment
	 * @return string
	 */
// 	public function createArchiveDeeGml($jddId, $fileName, $comment) {
// 		// Get filePath, fileName without extension
// 		$filePath = pathinfo($fileName, PATHINFO_DIRNAME);
// 		$fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);
		
// 		$configuration = Zend_Registry::get('configuration');
// 		$deePublicDir = $configuration->getConfig('deePublicDirectory');
		
// 		// JDD metadata id
// 		$jddModel = new Application_Model_RawData_Jdd();
// 		$jddRowset = $jddModel->find($jddId);
// 		$jddRowset->next();
// 		$uuid = $jddRowset->toArray()[0]['jdd_metadata_id'];
		
// 		$descriptionFile = $filePath . '/descriptif.txt';
// 		$out = fopen($descriptionFile, 'w');
// 		fwrite($out, "Export DEE pour le jeu de données $uuid \n \n");
// 		fwrite($out, "Raisons amenant à une génération ou regénération de la DEE : " . stripslashes($comment));
// 		fclose($out);
		
// 		// Create an archive of the whole directory
// 		$parentDir = dirname($filePath); // deePrivateDirectory
// 		$archiveName = $deePublicDir . '/' . $fileNameWithoutExtension . '.zip';
// 		chdir($parentDir);
// 		system("zip -r $archiveName $fileNameWithoutExtension");
		
// 		// Delete the other files
// 		unlink($descriptionFile);
		
// 		return $archiveName;
// 	}

	/**
	 * Create a file which describe the deletion reasons
	 * And put it in the public directory.
	 *
	 * @param
	 *        	$jddId
	 * @param
	 *        	$comment
	 * @return string
	 */
// 	public function createDeeGmlDeletionDescriptionFile($jddId, $comment) {
// 		// JDD metadata id
// 		$jddModel = new Application_Model_RawData_Jdd();
// 		$jddRowset = $jddModel->find($jddId);
// 		$jddRowset->next();
// 		$uuid = $jddRowset->toArray()[0]['jdd_metadata_id'];
		
// 		// The export file model
// 		$this->exportFileModel = new Application_Model_RawData_ExportFile();
// 		$exportfilePath = $this->exportFileModel->generateFilePath($uuid);
// 		$desciptionFilePrefix = substr($exportfilePath, 0, -4);
		
// 		$configuration = Zend_Registry::get('configuration');
// 		$deePublicDir = $configuration->getConfig('deePublicDirectory');
// 		$descriptionFile = $deePublicDir . '/' . pathinfo($exportfilePath, PATHINFO_FILENAME) . '_descriptif.txt';
// 		$out = fopen($descriptionFile, 'w');
// 		fwrite($out, "L'Export DEE pour le jeu de données $uuid a été supprimé. \n \n");
// 		if ($comment != "") {
// 			fwrite($out, "Raisons amenant à une suppression de la DEE : " . stripslashes($comment));
// 		}
// 		fclose($out);
		
// 		return $descriptionFile;
// 	}

	/**
	 * Send two notification emails after creation of the DEE archive:
	 * - one to the user who created the DEE
	 * - one to the MNHN
	 *
	 * @param
	 *        	$jddId
	 * @param
	 *        	$archivePath
	 * @param
	 *        	$dateCreated
	 * @param
	 *        	$userLogin
	 * @param
	 *        	$deeAction
	 * @param
	 *        	$comment
	 */
// 	public function sendDEENotificationMail($jddId, $archivePath, $dateCreated, $userLogin, $deeAction, $comment) {
// 		$configuration = Zend_Registry::get('configuration');
		
// 		$toEmailAddress = $configuration->getConfig('deeNotificationMail', 'sinp-dev@ign.fr');
		
// 		if ($deeAction == 'create') {
// 			$action = 'CREATION';
// 		} else if ($deeAction == 'update') {
// 			$action = 'MISE-A-JOUR';
// 		} else {
// 			$action = 'SUPPRESSION';
// 		}
		
// 		$regionCode = $configuration->getConfig('regionCode', 'REGION');
// 		$archiveFilename = pathinfo($archivePath, PATHINFO_BASENAME);
// 		$archiveUrl = $configuration->getConfig('site_url') . '/dee/' . $archiveFilename;
// 		$siteName = $configuration->getConfig('site_name');
// 		$md5 = md5_file($archivePath);
// 		// JDD metadata id
// 		$jddSession = new Zend_Session_Namespace('jdd');
		
// 		$jddModel = new Application_Model_RawData_Jdd();
// 		$jddRowset = $jddModel->find($jddId);
// 		$jddRowset->next();
// 		$jdd = $jddRowset->toArray()[0];
// 		$uuid = $jdd['jdd_metadata_id'];
// 		$submissionId = $jdd['submission_id'];
// 		// Provider of the submission and submission files
// 		$submissionModel = new Application_Model_RawData_CustomSubmission();
// 		$submission = $submissionModel->getSubmission($submissionId);
// 		$provider = $submission->providerLabel . " (" . $submission->providerId . ")";
// 		// Files of the submission
// 		$submissionFiles = $submissionModel->getSubmissionFiles($submissionId);
// 		$fileNames = array_map("basename", array_column($submissionFiles, "file_name"));
		
// 		// Contact user
// 		$userModel = new Application_Model_Website_User();
// 		$user = $userModel->getUser($userLogin);
// 		$userName = $user->username;
// 		$userEmail = $user->email;
		
// 		// -- Mail 1: to the MNHN
		
// 		// Title and body:
// 		$title = "[$regionCode] $action du jeu de données $uuid";
		
// 		$body = "Nom du fichier : $archiveFilename" . "\n" . "Date de $action du jeu : " . date('d/m/Y H:i:s', $dateCreated) . "\n" . "Fournisseur : " . $provider . "\n" . "Plate-forme : " . $siteName . "\n" . "Identifiant de métadonnées du jeu de données : " . $uuid . "\n" . "Contact : " . $userName . "\n" . "Courriel : " . $userEmail . "\n" . "Type d'envoi : " . $action . "\n" . "Commentaire : " . stripslashes($comment) . "\n" . "URL : <a href='$archiveUrl'>" . $archiveUrl . "</a>\n" . "CHECKSUM MD5 : " . $md5 . "\n";
		
// 		// -- Send the email
// 		// Using the mailer service based on SwiftMailer
// 		$mailerService = new Application_Service_MailerService();
// 		// Create a message
// 		$message = $mailerService->newMessage($title);
		
// 		// body
// 		$bodyMessage = "<p><strong>" . $configuration->getConfig('site_name') . " - " . $title . "</strong></p>" . "<p>" . nl2br($body) . "</p>";
		
// 		$message->setTo($toEmailAddress)->setBody($bodyMessage, 'text/html');
		
// 		// Send the message
// 		$mailerService->sendMessage($message);
		
// 		// -- Mail 2: to the user who created the DEE
// 		if ($deeAction != 'delete') {
// 			$title = "[$regionCode] Transmission du jeu de données $uuid à la plateforme nationale";
			
// 			$message = $mailerService->newMessage($title);
			
// 			$body = "<p>Bonjour,</p>";
// 			$body .= (count($submissionFiles) > 1) ? "<p>Les fichiers de données <em>%s</em> que vous nous avez transmis et dont l'identifiant de jeu de données est
//              <em>%s</em> ont été standardisés " : "<p>Le fichier de données <em>%s</em> que vous nous avez transmis et dont l'identifiant est
//              <em>%s</em> a été standardisé ";
// 			$body .= "au format GML d'échange de DEE le %s. La plateforme nationale a été notifiée et va procéder à l'intégration de
//             ce nouveau fichier dans l'INPN.</p>";
// 			$body .= "<p>Bien cordialement,</p>
//                <p>Contact : %s<br>Courriel: %s</p>";
// 		} else {
// 			$title = "[$regionCode] Suppression du jeu de données $uuid standardisé";
			
// 			$message = $mailerService->newMessage($title);
			
// 			$body = "<p>Bonjour,</p>";
// 			$body .= (count($submissionFiles) > 1) ? "<p>Les fichiers standardisés, correspondant aux fichiers de données <em>%s</em> que vous nous avez transmis et dont l'identifiant de jeu de données est
// 	             <em>%s</em> ont été supprimés " : "<p>Le fichier standardisé, correspondant au fichier de données <em>%s</em> que vous nous avez transmis et dont l'identifiant est
// 	             <em>%s</em> a été supprimé ";
// 			$body .= "le %s. La plateforme nationale a été notifiée et va procéder à la suppression de ces données dans l'INPN.</p>";
// 			$body .= "<p>Bien cordialement,</p>
// 	               <p>Contact : %s<br>Courriel: %s</p>";
// 		}
// 		$body = sprintf($body, implode($fileNames, ", "), $uuid, date("d/m/Y"), $user->username, $user->email);
		
// 		$message->setTo($user->email)->setBody($body, 'text/html');
		
// 		// Send the message
// 		$mailerService->sendMessage($message);
// 	}
}