<?php

require_once CUSTOM_APPLICATION_PATH . '/gmlexport/vendor/autoload.php';
require_once CUSTOM_APPLICATION_PATH . '/gmlexport/DEEModel.php';
require_once CUSTOM_APPLICATION_PATH . '/services/JobManagerService.php';

/**
 * Class GMLExport
 *
 * Class for the GML export of the DEE
 */
class GMLExport
{
    // The export uses twig templates
    protected $twig;
    // Class DEEModel
    protected $dee;
    // counter used to construct file-unique gml ids
    protected $gmlId;

    /**
     * GMLExport constructor.
     */
    public function __construct()
    {
        // Bootstrap Zend
        $application = new Zend_Application(APPLICATION_ENV, $ApplicationConf);
        $application->getBootstrap()->bootstrap();

        // Initialise the logger
        $this->logger = Zend_Registry::get("logger");

        // Instantiate job manager
        $this->jm = new Application_Service_JobManagerService();

        // Instantiate DEEModel
        $this->dee = new DEEModel();

        // Instantiate twig
        $loader = new Twig_Loader_Filesystem(CUSTOM_APPLICATION_PATH . '/gmlexport/templates');
        $this->twig = new Twig_Environment($loader, array(
            // 'cache' => CUSTOM_APPLICATION_PATH . '/gmlexport/templates/cache',
            'cache' => false,
            'debug' => true,
        ));
        $this->twig->addExtension(new Twig_Extension_Debug());

        // gmlId is generated as a sequence of integer
        $this->gmlId = 1;

    }

    /**
     * Create the GML of the DEE for submission $submissionId.
     * Write it in file $fileName.
     * Optionally, a job id can be passed to the function, in which case
     * the function reports its progress percentage to the Job Manager.
     *
     * Principle:
     * - create 4 intermediate files :
     *   - header and end of the gml file (independent from the submission)
     *   - observations
     *   - groups (computed from observations)
     * - concatenate the intermediate files and delete them
     *
     * @param $submissionId
     * @param $fileName
     * @param null $jobId
     * @throws Exception
     */
    public function generateDeeGml($submissionId, $fileName, $jobId = null) {
        // Configure memory and time limit because the program ask a lot of resources
        $configuration = Zend_Registry::get("configuration");
        ini_set("memory_limit", $configuration->getConfig('memory_limit', '1024M'));
        ini_set("max_execution_time", 0); // Not really useful because the script is used in CLI (max_execution_time is already 0)

        $schema = 'RAW_DATA';

        // Get the dataset Id
        $submissionModel = new Application_Model_RawData_Submission();
        $dataSubmission = $submissionModel->getSubmission($submissionId);
        if (!$dataSubmission) {
            throw new Exception("Could not find submission $submissionId");
        }
        $datasetId = $dataSubmission->datasetId;
        $this->logger->debug('generateDEE.php - Submission Id : ' . $submissionId . " DatasetId : " . $datasetId);

        // -- Create a query object : the query must find all lines with given submission_id,
        // And print a list of all fields in the model

        $queryObject = new Application_Object_Generic_DataObject();
        $queryObject->datasetId = $datasetId;

        $customMetadataModel = new Application_Model_Metadata_CustomMetadata();
        $modelId = $customMetadataModel->getModelForDataset($datasetId);
        $tableFields = $customMetadataModel->getTableFieldsForModel($modelId);

        // -- Criteria fields for the query
        foreach ($tableFields as $tableField) {
            switch ($tableField->data) {
                case 'SUBMISSION_ID':
                    $tableField->value = $submissionId;
                    break;
            }
            $queryObject->addInfoField($tableField);
        }

        // -- Result fields for the query : all fields of the model
        foreach ($tableFields as $tableField) {
            $queryObject->addEditableField($tableField);
        }
        $resultColumns = $queryObject->getEditableFields();

        // -- Order fields
        $genericService = new Application_Service_GenericService();
        $sqlPKey = $genericService->generateSQLPrimaryKey($schema, $queryObject);

        // -- Generate the SQL Request

        // To generate unique ids for the gml, we put a marker wich is replaced by an id afterwards
        $gml_id = "'ID#GMLID#'"; // enclose it in simple quotes to be recognized as a string in ST_AsGML

        $options = array(
            'geometry_format' => 'gml',
            'geometry_srs' => 4326,
            'gml_precision' => 8,
            'gml_id' => $gml_id,
            'datetime_format' => 'YYYY-MM-DD"T"HH24:MI:SSTZ',
        );

        $select = $genericService->generateSQLSelectRequest($schema, $queryObject, $options);
        $from = $genericService->generateSQLFromRequest($schema, $queryObject);
        $where = $genericService->generateSQLWhereRequest($schema, $queryObject);
        $order = " ORDER BY " . $sqlPKey;
        $sql = $select . $from . $where . $order;

        $genericModel = new Application_Model_Generic_Generic();

        // Count Results
        $countResult = $genericModel->executeRequest("SELECT COUNT(*) as count " . $from . $where);
        $total = $countResult[0]['count'];

        // -- Export results to a GML file

        if ($total != 0) {

            // Opens a file for observations ($filename."_observations")
            $filePath = pathinfo($fileName, PATHINFO_DIRNAME);
            $pathExists = is_dir($filePath) || mkdir($filePath, 0755, true);
            if (!$pathExists) {
                throw new Exception("Error: could not create directory: $filePath");
            }
            $fileNameObservations = $fileName . "_observations";
            $out = fopen($fileNameObservations, 'w');
            if (!$out) {
                throw new Exception("Error: could not open (w) file: $fileName");
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

            for ($i = 0; $i < $batches; $i++) {

                $batchSQL = $sql . " LIMIT $batchLines OFFSET " . $i * $batchLines;

                // -- Execute query and put results in a formatted array of strings
                $results = $genericModel->executeRequest($batchSQL);

                // Put lines in a formatted array
                $resultsArray = array();
                foreach ($results as $line) {
                    $resultLine = array();
                    foreach ($resultColumns as $tableField) {

                        $key = strtolower($tableField->getName());
                        $value = $line[$key];
                        $data = $tableField->data;
                        // $formField = $formFields[$key];

                        if ($value == null) {
                            $resultLine[$data] = '';
                        } else {
                            switch ($tableField->type) {
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
                    'site_name' => $configuration->getConfig('site_name', 'Plateforme GINCO-SINP')
                );
                $this->generateObservationsGML($resultsArray, $fileNameObservations, $params, $jobId, $i * $batchLines, $total);

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
        return $total!=0;
    }


    /**
     * Generate the observations part of the gml for the DEE
     * and write it to an output (intermediate) file.
     * Write in "append" mode, so the file can be wited to by batches.
     *
     * @param $observations : batch of observations
     * @param $outputFile : file name to write.
     * @param array $params : associative id of parameters
     * @param int|null $jobId : job id in the job_queue table. If not null, the function will write its progress in the job_queue table.
     * @param $startLine : start line of the batch in the entire list of observations
     * @param $total : total nb of the entire list of observations
     * @throws Exception
     */
    protected function generateObservationsGML($observations, $outputFile, $params = null, $jobId = null, $startLine = 0, $total = 0)
    {
        // Open the file in append mode
        $out = fopen($outputFile, 'a');
        if (!$out) {
            throw new Exception("Error: could not open (a) file: $outputFile");
        }

        // Write Observations
        foreach ($observations as $index => $observation) {
            $observation = $this->dee->formatDates($observation);
            $observation = $this->dee->transformCodes($observation);
            $observation = $this->dee->fillValues($observation, array(
                'orgtransformation' => (isset($params['site_name']) ? $params['site_name'] : 'Plateforme GINCO-SINP')
            ));
            $observation = $this->dee->specialCharsXML($observation);
            $observation = $this->dee->structureObservation($observation);

            fwrite($out, $this->strReplaceBySequence("#GMLID#", $this->generateObservation($observation)));

            // report progress every 1, 10, 100 or 1000 lines
            if ($jobId) {
                $progress = $startLine + $index + 1;
                if (($total <= 100) ||
                    ($total <= 1000 && ($progress % 10 == 0)) ||
                    ($total <= 10000 && ($progress % 100 == 0)) ||
                    ($progress % 1000 == 0)
                ) {
                    $this->jm->setProgress($jobId, $progress);
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
     * @param $groups
     * @param $outputFile
     * @throws Exception
     */
    protected function generateGroupsGML($groups, $outputFile)
    {
        // Open the file in write mode
        $out = fopen($outputFile, 'w');
        if (!$out) {
            throw new Exception("Error: could not open (w) file: $outputFile");
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
     * @param $outputFile
     * @throws Exception
     */
    protected function generateHeaderGML($outputFile)
    {
        // Open the file in write mode
        $out = fopen($outputFile, 'w');
        if (!$out) {
            throw new Exception("Error: could not open (w) file: $outputFile");
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
     * @param $outputFile
     * @throws Exception
     */
    protected function generateEndGML($outputFile)
    {
        // Open the file in write mode
        $out = fopen($outputFile, 'w');
        if (!$out) {
            throw new Exception("Error: could not open (w) file: $outputFile");
        }
        // Write GML header
        fwrite($out, $this->twig->render('end.xml'));
        // Close
        fclose($out);
    }

    /**
     * Generate a string of the GML for one group
     *
     * @param $group
     * @return string
     */
    protected function generateRegroupement($group)
    {
        $part = $this->twig->render('regroupement.xml.twig', array('regroupement' => $group));
        return $part;
    }

    /**
     * Generate a string of the GML for one observation
     *
     * @param $observation
     * @return string
     */
    protected function generateObservation($observation)
    {
        $part = $this->twig->render('sujet_observation.xml.twig', array('observation' => $observation));
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
    protected function strReplaceBySequence($needle, $str)
    {
        while (($pos = strpos($str, $needle)) !== false) {
            $str = substr_replace($str, $this->gmlId, $pos, strlen($needle));
            $this->gmlId++;
        }
        return $str;
    }

    /**
     * Create a gzipped archive containing:
     * - the dee gml file
     * - other files...
     * And put the archive in the "deePublicDirectory" from conf (in the public directory).
     *
     * @param $submissionId
     * @param $fileName
     */
    public function createArchiveDeeGml($submissionId, $fileName)
    {
        // Get filePath, fileName without extension
        $filePath = pathinfo($fileName, PATHINFO_DIRNAME);
        $fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

        // Put other file in the directory to create a real test archive - todo use it to put something useful !
        $otherFile = $filePath . '/truc.txt';
        $out = fopen($otherFile,'w');
        fwrite($out, "Export DEE pour la soumission $submissionId");
        fclose($out);

        // Create an archive of the whole directory
        $configuration = Zend_Registry::get('configuration');
        $archiveName = $configuration->getConfig('deePublicDirectory') . '/' . $fileNameWithoutExtension . '.tar.gz';

        system("tar zcf $archiveName $filePath");

        return $archiveName;
    }
}