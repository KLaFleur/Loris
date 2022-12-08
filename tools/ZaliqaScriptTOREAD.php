<?php
require_once __DIR__ . '/../../tools/generic_includes.php';

use GuzzleHttp\Client;

$verbose = false;
if (isset($argv[1]) && $argv[1] == 'verbose') {
    $verbose = true;
}
$skipped_cands  = [];
$new_visits     = [];
$new_containers = [];
$new_specimens  = [];
$errors         = [];

// Set up API settings
$apiSettings = $config->getSetting('API');
$qpnBaseURL  = $apiSettings['qpnapi']['baseurl'];
$cbigBaseURL = $apiSettings['cbigapi']['baseurl'];
$qpnCred     = [
    'username' => $apiSettings['qpnapi']['username'],
    'password' => $apiSettings['qpnapi']['password'],
];
$cbigCred    = [
    'username' => $apiSettings['cbigapi']['username'],
    'password' => $apiSettings['cbigapi']['password'],
];

// Initialize Guzzle
echo "\nConnecting to APIs ...\n";
try {
    $qpnClient  = new GuzzleHttp\Client(['baseurl' => $qpnBaseURL]);
} catch (Exception $e) {
    echo "\nException when initializing Guzzle Client with baseurl $qpnBaseURL: ", $e->getMessage(), PHP_OEL;
    die();
}
try {
    $cbigClient = new Client(['base_uri' => $cbigBaseURL]);
} catch (Exception $e) {
    echo "\nException when initializing Guzzle Client with baseurl $cbigBaseURL: ", $e->getMessage(), PHP_OEL;
    die();
}

// Login and get API tokens
try {
    $qpnLoginResp = $qpnClient->post("$qpnBaseURL/login", ['json' => $qpnCred]);
} catch (Exception $e) {
    echo "\nException when calling Client->post($qpnBaseURL/login): ", $e->getMessage(), PHP_EOL;
    die();
}
try {
    $cbigLoginResp = $cbigClient->post("$cbigBaseURL/login", ['json' => $cbigCred]);
} catch (Exception $e) {
    echo "\nException when calling Client->post($cbigBaseURL/login): ", $e->getMessage(), PHP_EOL;
    die();
}

$qpnToken  = json_decode($qpnLoginResp->getBody()->getContents())->token ?? null;
$cbigToken = json_decode($cbigLoginResp->getBody()->getContents())->token ?? null;

if ($qpnToken === null || $cbigToken === null) {
    echo "\nLogin failed: tokens not retrieved. Check credentials.";
    die();
}
// Set headers with Bearer token
$qpnHeaders  = [
    'Authorization' => "Bearer $qpnToken"
];
$cbigHeaders = [
    'Authorization' => "Bearer $cbigToken"
];

echo "\nGetting data ...\n";
// Call QPN API
// Get QPN candidates
$qpnCandidates = getCandidates(
    $qpnClient,
    "$qpnBaseURL/candidates/ext/",
    $qpnHeaders
);
if (empty($qpnCandidates)) {
    echo "\nFailed to get QPN Candidate data.\n";
    die();
}
// Get QPN specimens
$qpn_shortened_url = str_replace('/api/v0.0.3-dev', '', $qpnBaseURL);
$qpnSpecimens      = handleGET(
    $qpnClient,
    "$qpn_shortened_url/biobank/specimenendpoint/",
    $qpnHeaders
);
if (empty($qpnSpecimens)) {
    echo "\nFailed to get QPN Specimen data.\n";
    die();
}
// Get QPN containers
$qpnContainers = handleGET(
    $qpnClient,
    "$qpn_shortened_url/biobank/containerendpoint/",
    $qpnHeaders
);
if (empty($qpnContainers)) {
    echo "\nFailed to get QPN Container data.\n";
    die();
}

// Call CBIG API
// Get CBIG candidates
$cbigCandidates = getCandidates(
    $cbigClient,
    "$cbigBaseURL/candidates/ext/",
    $cbigHeaders
);
if (empty($cbigCandidates)) {
    echo "\nFailed to get CBIG Candidate data.\n";
    die();
}
// Get CBIG specimens
$cbig_shortened_url = str_replace('/api/v0.0.3-dev', '', $cbigBaseURL);
$cbigSpecimens      = handleGET(
    $cbigClient,
    "$cbig_shortened_url/biobank/specimenendpoint/",
    $cbigHeaders
);
if (empty($cbigSpecimens)) {
    echo "\nFailed to get CBIG Specimen data.\n";
    die();
}
// Get CBIG containers
$cbigContainers = handleGET(
    $cbigClient,
    "$cbig_shortened_url/biobank/containerendpoint/",
    $cbigHeaders
);
if (empty($cbigContainers)) {
    echo "\nFailed to get CBIG Container data.\n";
    die();
}
// Get CBIG options
$cbigOptions = handleGET(
    $cbigClient,
    "$cbig_shortened_url/biobank/optionsendpoint/",
    $cbigHeaders
);
if (empty($cbigOptions)) {
    echo "\nFailed to get CBIG Options data.\n";
    die();
}

// Handle data arrays
$qpnCandidateData = [];
$cbigSpecimenData = [];
// Index QPN candidate data with 'PSCID' in new array
foreach ($qpnCandidates as $candidate) {
    $qpnCandidateData[$candidate['PSCID']] = $candidate;
}
// Index CBIG specimen data with 'candidateid' in new array
foreach ($cbigSpecimens as $specimen) {
    $cbigSpecimenData[$specimen['candidateId']][] = $specimen;
}

// Set up data lists for mapping between QPN and CBIG
$qpnSiteList      = \Utility::getSiteList();
$qpnProjectList   = \Utility::getProjectList();
$cbigSiteList     = $cbigOptions['centers'];
$cbigProjectList  = $cbigOptions['projects'];
$cbigSessionList  = [];
$cbigExaminerList = [];
// Handle sessions list
foreach ($cbigOptions['sessions'] as $sessionID => $session) {
    $cbigSessionList[$sessionID] = $session['label'];
}
// Handle examiner list
foreach ($cbigOptions['examiners'] as $examinerID => $examiner) {
    $cbigExaminerList[$examinerID] = $examiner['label'];
}

// START IMPORT
echo "\nStarting import ...\n";
// Create list of QPN specimens from CBIG
$specimenList = [];
foreach ($cbigCandidates as $cbigCandidate) {
    $cbigExtID  = $cbigCandidate['ExtStudyID'];
    $cbigCandID = $cbigCandidate['CandID'];
    // If CBIG ExtStudyID is a valid QPN PSCID,
    // populate specimenList array
    if (!isset($qpnCandidateData[$cbigExtID])) {
        $skipped_cands[] = $cbigExtID;
        continue;
    }
    echo "\n\tCBIG candidate $cbigCandID with QPN PSCID $cbigExtID ...\n";
    $qpnCandidate       = $qpnCandidateData[$cbigExtID];
    $candidateSpecimens = $cbigSpecimenData[$cbigCandID] ?? null;
    if (is_null($candidateSpecimens)) {
        echo "\n\t\tNo specimen data available for this candidate.\n";
        continue;
    }
    foreach ($candidateSpecimens as $specimen) {
        // Map data to QPN IDs and add to specimen list
        // Set container
        $specimenID      = $specimen['id'];
        $containerID     = $specimen['containerId'];
        $container       = $cbigContainers[$containerID];
        $containerBarcode = $container['barcode'];
        $newContainer    = mapContainer(
            $container,
            $cbigProjectList,
            $qpnProjectList,
            $cbigSiteList,
            $qpnSiteList,
            $errors
        );
        if (empty($newContainer)) {
            echo "\n\t\tContainer $containerBarcode could not be properly mapped.";
            echo "\n\t\tSkipping specimen $specimenID.\n";
            $errors['specimen'][$specimenID][] = "Container $containerBarcode could not be properly mapped.";
            continue;
        }

        // Check if session exists before mapping specimen
        // Find QPN visit label
        $sessionID      = $specimen['sessionId'];
        $cbigVisitLabel = $cbigSessionList[$sessionID];
        $qpnVisitLabel  = getQPNVisitLabelByCBIGVisitLabel($cbigVisitLabel);
        if (is_null($qpnVisitLabel)) {
            echo "\n\t\tVisit label match for $cbigVisitLabel could not be found in QPN.";
            echo "\n\t\tSkipping specimen $specimenID.\n";
            $errors['specimen'][$specimenID][] = "Visit label match for $cbigVisitLabel could not be found.";
            continue;
        }
        // Find QPN site
        $cbigCenterID = $specimen['collection']['centerId'];
        $qpnCenterID  = getQPNCenterIDByCBIGCenterID(
            $cbigCenterID,
            $cbigSiteList,
            $qpnSiteList
        );
        if (is_null($qpnCenterID)) {
            echo "\n\t\tCenterID $cbigCenterID match not found for Specimen $specimenID.";
            echo "\n\t\tSkipping specimen $specimenID.\n";
            $errors['specimen'][$specimenID][] = "CenterID $cbigCenterID match not found.";
            continue;
        }
        $qpnSiteName = $qpnSiteList[$qpnCenterID];
        // Find subproject
        $candidateVisitResp = $cbigClient->get(
            "$cbigBaseURL/candidates/$cbigCandID/$cbigVisitLabel",
            ['headers' => $cbigHeaders]
        );
        $candidateVisit      = $candidateVisitResp->getBody()->getContents();
        $candidateVisit      = json_decode($candidateVisit, true);
        $candidateSubproject = $candidateVisit['Meta']['Battery'];
        // Create timepoint
        if (!isset($qpnCandidate['SessionIDs'][$qpnVisitLabel])) {
            $qpnCandidateID = $qpnCandidate['CandID'];
            echo "\n\t\tCreating timepoint $qpnVisitLabel for QPN candidate $qpnCandidateID...\n";
            $success = createTimePoint(
                $qpnCandidateID,
                $qpnVisitLabel,
                $qpnSiteName,
                $candidateSubproject,
                $qpnClient,
                $qpnBaseURL,
                $qpnHeaders
            );
            if (!$success) {
                echo "\n\t\tUnable to create $qpnVisitLabel timepoint for candidate $qpnCandidateID.";
                echo "\n\t\tSkipping specimen $specimenID.\n";
                $errors['specimen'][$specimenID][] = "Unable to create $qpnVisitLabel timepoint for candidate $qpnCandidateID.";
                continue;
            }
            echo "\n\t\tTimepoint $qpnVisitLabel successfully created for candidate $qpnCandidateID.\n";
            $new_visits[$qpnCandidateID][] = $qpnVisitLabel;
            // Get QPN candidates with latest timepoints
            $qpnCandidates = getCandidates(
                $qpnClient,
                "$qpnBaseURL/candidates/ext/",
                $qpnHeaders
            );
            // Reassign candidate with new timepoint data
            $qpnCandidate = $qpnCandidates[$qpnCandidateID];
        }

        // Set specimen
        $newSpecimen = mapSpecimen(
            $specimen,
            $cbigSiteList,
            $qpnSiteList,
            $qpnVisitLabel,
            $cbigExaminerList,
            $qpnCandidate,
            $errors
        );
        if (empty($newSpecimen)) {
            echo "\n\t\tSpecimen $specimenID could not be properly mapped.";
            echo "\n\t\tSkipping specimen $specimenID.\n";
            $errors['specimen'][$specimenID][] = "Could not be properly mapped.";
            continue;
        }
        $newSpecimen['container']  = $newContainer;
        $specimenList[$specimenID] = $newSpecimen;
        if ($verbose) {
            echo"\n\t\tAdded specimen $specimenID to specimen list.\n";
        }
    }
}
if ($verbose) {
    echo "\nSpecimen list:\n";
    print_r($specimenList);
}
// PUT specimens and containers to QPN
echo "\nCalling API: PUT containers and specimens ...\n";
$insertedContainerIds = [];
$insertedSpecimenIds  = [];
foreach ($specimenList as $specimenID => $specimen) {
    $container        = $specimen['container'];
    $containerID      = $container['id'];
    $containerBarcode = $container['barcode'];

    // Insert specimen container first
    $insertedContainerIds = insertContainer(
        $container,
        $cbigContainers,
        $qpnContainers,
        $insertedContainerIds,
        $cbigProjectList,
        $qpnProjectList,
        $cbigSiteList,
        $qpnSiteList,
        $qpnClient,
        $qpn_shortened_url,
        $qpnHeaders,
        $errors
    );
    if (!in_array($containerID, $insertedContainerIds)) {
        echo "\n\tContainer $containerBarcode could not be inserted.";
        echo "\n\t\tSkipping inserting specimen $specimenID...\n";
        $errors['container'][$containerBarcode][] = "Could not be inserted.";
        continue;
    }

    // Insert specimen
    $insertedSpecimenIds = insertSpecimen(
        $specimen,
        $specimenList,
        $qpnSpecimens,
        $insertedSpecimenIds,
        $qpnClient,
        $qpn_shortened_url,
        $qpnHeaders,
        $errors
    );
    if (!in_array($specimenID, $insertedSpecimenIds)) {
        echo "\n\tSpecimen $specimenID could not be inserted.\n";
        $errors['specimen'][$specimenID][] = "Could not be inserted.";
        continue;
    }
}
$new_containers = $insertedContainerIds;
$new_specimens  = $insertedSpecimenIds;

// END IMPORT
echo "\nBiobank import complete.\n";
echo "\nNew visits: ".count($new_visits)."\n";
print_r($new_visits);
if ($verbose) {
    echo "\nNew containers: ".count($new_containers)."\n";
    print_r($new_containers);
    echo "\nNew specimens: ".count($new_specimens)."\n";
    print_r($new_specimens);
}
echo "\nSkipped candidates with no matching external ID: ".count($skipped_cands)."\n";
print_r($skipped_cands);
echo "\nErrors:\n";
print_r($errors);

// CUSTOM FUNCTIONS
function mapContainer(
    array $container,
    array $cbigProjectList,
    array $qpnProjectList,
    array $cbigSiteList,
    array $qpnSiteList,
    array &$errors
) : array {
    // Initialize vars
    $qpnCenterID      = null;
    $containerID      = $container['id'];
    $containerBarcode = $container['barcode'];
    $newContainer     = $container;

    // Map ProjectID
    $cbigProjectIDs = $container['projectIds'];
    $newContainer['projectIds'] = [];
    foreach ($cbigProjectIDs as $cbigProjectID) {
        $qpnProjectID = getQPNProjectIDByCBIGProjectID(
            $cbigProjectID,
            $cbigProjectList,
            $qpnProjectList
        );
        if (is_null($qpnProjectID)) {
            // Skip project IDs if don't exist in QPN
            echo "\n\tProjectID $cbigProjectID match not found for Container $containerBarcode.";
            echo "\n\tSkipping mapping CBIG ProjectID $cbigProjectID..\n";
            continue;
        }
        $newContainer['projectIds'][] = $qpnProjectID;
    }
    if (empty($newContainer['projectIds'])) {
        echo "\nNo project ID matches found for Container $containerBarcode.";
        echo "\nSkipping container $containerBarcode...\n";
        $errors['container'][$containerBarcode][] = "No project ID matches found.";
        return [];
    }

    // Map CenterID
    $cbigCenterID = $container['centerId'];
    $qpnCenterID  = getQPNCenterIDByCBIGCenterID(
        $cbigCenterID,
        $cbigSiteList,
        $qpnSiteList
    );
    if (is_null($qpnCenterID)) {
        echo "\nCenterID $cbigCenterID match not found for Container $containerBarcode.";
        echo "\nSkipping container $containerBarcode...\n";
        $errors['container'][$containerBarcode][] = "CenterID $cbigCenterID match not found.";
        return [];
    }
    $newContainer['centerId'] = $qpnCenterID;

    return $newContainer;
}

function mapSpecimen(
    array  $specimen,
    array  $cbigSiteList,
    array  $qpnSiteList,
    string $qpnVisitLabel,
    array  $cbigExaminerList,
    array  $qpnCandidate,
    array  &$errors
) : array {
    $newSpecimen = $specimen;
    $specimenID  = $specimen['id'];

    // map session
    $newSpecimen['sessionId'] = $qpnCandidate['SessionIDs'][$qpnVisitLabel];

    // map collection centerId
    $collectionCenterID = $specimen['collection']['centerId'];
    $newCollectionCenterID = getQPNCenterIDByCBIGCenterID(
        $collectionCenterID,
        $cbigSiteList,
        $qpnSiteList
    );
    if (is_null($newCollectionCenterID)) {
        echo "\nCenterID $collectionCenterID match not found for SpecimenID $specimenID.";
        $errors['specimen'][$specimenID][] = "CenterID $collectionCenterID match not found.";
        return [];
    }
    $newSpecimen['collection']['centerId'] = $newCollectionCenterID;

    // map collection examinerId
    $collectionExaminerID = $specimen['collection']['examinerId'];
    $newExaminerID = getQPNExaminerIDByCBIGExaminerID(
        $collectionExaminerID,
        $cbigExaminerList
    );
    if (is_null($newExaminerID)) {
        echo "\nExaminerID $collectionExaminerID match not found for SpecimenID $specimenID.";
        $errors['specimen'][$specimenID][] = "ExaminerID $collectionExaminerID match not found.";
        return [];
    }
    $newSpecimen['collection']['examinerId'] = $newExaminerID;

    // handle preparation data
    if (isset($specimen['preparation'])) {
        // map preparation centerId
        $prepCenterID = $specimen['preparation']['centerId'];
        $newPrepCenterID = getQPNCenterIDByCBIGCenterID(
            $prepCenterID,
            $cbigSiteList,
            $qpnSiteList
        );
        if (is_null($newPrepCenterID)) {
            echo "\nCenterID $prepCenterID match not found for SpecimenID $specimenID.";
            $errors['specimen'][$specimenID][] = "CenterID $prepCenterID not found.";
            return [];
        }
        $newSpecimen['preparation']['centerId'] = $newPrepCenterID;

        // map preparation examinerId
        $prepExaminerID = $specimen['preparation']['examinerId'];
        $newPrepExaminerID = getQPNExaminerIDByCBIGExaminerID(
            $prepExaminerID,
            $cbigExaminerList
        );
        if (is_null($newPrepExaminerID)) {
            echo "\nExaminerID $prepExaminerID match not found for SpecimenID $specimenID.";
            $errors['specimen'][$specimenID][] = "ExaminerID $prepExaminerID match not found.";
            return [];
        }
        $newSpecimen['preparation']['examinerId'] = $newPrepExaminerID;
    }

    return $newSpecimen;
}

function insertContainer(
    array             $container,
    array             $cbigContainers,
    array             $qpnContainers,
    array             $insertedContainerIds,
    array             $cbigProjectList,
    array             $qpnProjectList,
    array             $cbigSiteList,
    array             $qpnSiteList,
    GuzzleHttp\Client $qpnClient,
    string            $qpn_shortened_url,
    array             $qpnHeaders,
    array             &$errors
) : array {
    // Unset child containers so that they don't
    // fail ContainerController->validateProjectIds
    // by not yet existing in the DB
    if (isset($container['childContainerIds'])) {
        unset($container['childContainerIds']);
    }

    // Check if container has parents and insert parents first
    $containerID      = $container['id'];
    $containerBarcode = $container['barcode'];
    if (isset($container['parentContainerId'])
        && !in_array($container['parentContainerId'], $insertedContainerIds)
    ) {
        $parentContainer        = $cbigContainers[$container['parentContainerId']];
        $parentContainerBarcode = $parentContainer['barcode'];
        // Skip parent container for which an insert was already attempted
        if (isset($errors['parentContainer']) && array_key_exists($parentContainerBarcode, $errors['parentContainer'])
        ) {
            return $insertedContainerIds;
        }
        $mappedParentContainer = mapContainer(
            $parentContainer,
            $cbigProjectList,
            $qpnProjectList,
            $cbigSiteList,
            $qpnSiteList,
            $errors
        );
        if (empty($mappedParentContainer)) {
            echo "\n\tParent container $parentContainerBarcode could not be properly mapped:";
            print_r($parentContainer);
            echo "\n\t\tSkipping inserting container $containerBarcode...\n";
            $errors['parentContainer'][$parentContainerBarcode][$containerBarcode][] = "Parent container could not be mapped.";
            return $insertedContainerIds;
        }
        // Insert parent containers
        $insertedContainerIds = insertContainer(
            $mappedParentContainer,
            $cbigContainers,
            $qpnContainers,
            $insertedContainerIds,
            $cbigProjectList,
            $qpnProjectList,
            $cbigSiteList,
            $qpnSiteList,
            $qpnClient,
            $qpn_shortened_url,
            $qpnHeaders,
            $errors
        );
        if (!in_array($container['parentContainerId'], $insertedContainerIds)) {
            echo "\n\tParent container $parentContainerBarcode could not be inserted.";
            echo "\n\t\tSkipping inserting container $containerBarcode...\n";
            $errors['parentContainer'][$parentContainerBarcode][$containerBarcode][] = "Parent container could not be inserted.";
            return $insertedContainerIds;
        }
    }

    echo "\n\tInserting container $containerBarcode ...";
    $response = new \LORIS\Http\Response();
    if (!isset($qpnContainers[$containerID])) {
        // POST to create container
        $response = $qpnClient->post(
            "$qpn_shortened_url/biobank/containerendpoint", [
                'headers' => $qpnHeaders,
                'json'    => [$container]
            ]
        );
    } else {
        // PUT to update container
        $response = $qpnClient->put(
            "$qpn_shortened_url/biobank/containerendpoint", [
                'headers' => $qpnHeaders,
                'json'    => $container
            ]
        );
    }
    if ($response->getStatusCode() != 200) {
        echo($response->getBody()->__toString());
        $errors['container'][$containerBarcode][] = "Could not be inserted.";
        return $insertedContainerIds;
    }
    echo "\n\t\tContainer $containerBarcode successfully inserted.\n";
    $insertedContainerIds[] = $containerID;

    return $insertedContainerIds;
}

function insertSpecimen(
    array             $specimen,
    array             $specimenList,
    array             $qpnSpecimens,
    array             $insertedSpecimenIds,
    GuzzleHttp\Client $qpnClient,
    string            $qpn_shortened_url,
    array             $qpnHeaders,
    array             &$errors
) : array {
    // Check if specimen has parents and insert parents first
    $specimenID = $specimen['id'];
    if (isset($specimen['parentSpecimenIds'])) {
        $parentSpecimenIds = $specimen['parentSpecimenIds'];
        foreach ($parentSpecimenIds as $parentSpecimenId) {
            if (!in_array($parentSpecimenId, $insertedSpecimenIds)) {
                $parentSpecimen = $specimenList[$parentSpecimenId] ?? null;
                if (is_null($parentSpecimen)) {
                    echo "\n\tParent specimen $parentSpecimenId not in specimen list.";
                    echo "\n\t\tSkipping inserting specimen $specimenID ...";
                    $errors['parentSpecimen'][$parentSpecimenId][$specimenID][] = "Parent specimen was not in specimen list.";

                    return $insertedSpecimenIds;
                }
                $insertedSpecimenIds = insertSpecimen(
                    $parentSpecimen,
                    $specimenList,
                    $qpnSpecimens,
                    $insertedSpecimenIds,
                    $qpnClient,
                    $qpn_shortened_url,
                    $qpnHeaders,
                    $errors
                );
                if (!in_array($parentSpecimenId, $insertedSpecimenIds)) {
                    echo "\n\tParent specimen $parentSpecimenId could not be inserted.";
                    echo "\n\t\tSkipping inserting specimen $specimenID ...";
                    $errors['parentSpecimen'][$parentSpecimenId][$specimenID][] = "Parent specimen could not be inserted.";

                    return $insertedSpecimenIds;
                }
            }
        }
    }
    echo "\n\tInserting specimen $specimenID ...";
    $response = new \LORIS\Http\Response();
    if (!isset($qpnSpecimens[$specimenID])) {
        // POST to create specimen
        $response = $qpnClient->post(
            "$qpn_shortened_url/biobank/specimenendpoint", [
                'headers' => $qpnHeaders,
                'json'    => [$specimen]
            ]
        );
    } else {
        // PUT to update specimen
        $response = $qpnClient->put(
            "$qpn_shortened_url/biobank/specimenendpoint", [
                'headers' => $qpnHeaders,
                'json'    => $specimen
            ]
        );
    }
    if ($response->getStatusCode() != 200) {
        echo($response->getBody()->__toString());
        $errors['specimen'][$specimenID][] = "Could not be inserted.";
        return $insertedSpecimenIds;
    }
    echo "\n\t\tSpecimen $specimenID successfully inserted.\n";
    $insertedSpecimenIds[] = $specimenID;

    return $insertedSpecimenIds;
}

/**
 * Get QPN ProjectID for the CBIG ProjectID
 *
 * @param  int $cbigProjectID ProjectID associated with project in CBIG
 *
 * @return ?int ID of the project in QPN
 */
function getQPNProjectIDByCBIGProjectID(
    int   $cbigProjectID,
    array $cbigProjectList,
    array $qpnProjectList
) : ?int {
    $cbigProjectName = $cbigProjectList[$cbigProjectID] ?? null;
    if (is_null($cbigProjectName)) {
        return null;
    }
    $qpnProjectID = array_search($cbigProjectName, $qpnProjectList);

    if ($qpnProjectID === false) {
        echo "\n\tThere is no project match in QPN for $cbigProjectName.";
        return null;
    }
    return $qpnProjectID;
}

function getQPNCenterIDByCBIGCenterID(
    int   $cbigCenterID,
    array $cbigSiteList,
    array $qpnSiteList
) : ?int {
    $cbigSiteName = $cbigSiteList[$cbigCenterID] ?? null;
    if (is_null($cbigSiteName)) {
        return null;
    };
    // Correct mis-match site names
    if ($cbigSiteName == 'CRU-MNI') {
        $cbigSiteName = 'Montreal Neurological Institute';
    } else if ($cbigSiteName == 'Centre Hospitalier Universitaire de Québec (QPN)') {
        $cbigSiteName = 'CHU Quebec';
    } else if ($cbigSiteName == 'Centre Hospitalier de l’Université de Montréal (QPN)') {
        $cbigSiteName = 'CHU Montreal';
    }
    $qpnCenterID = null;
    foreach ($qpnSiteList as $key => $qpnSiteName) {
        if (strpos($cbigSiteName, $qpnSiteName) !== false) {
            $qpnCenterID = $key;
            break;
        }
    }
    if (is_null($qpnCenterID)) {
        echo "\nThere is no site match in QPN for $cbigSiteName.\n";
        return null;
    }
    return (int)$qpnCenterID;
}

function getQPNExaminerIDByCBIGExaminerID(
    int   $cbigExaminerID,
    array $cbigExaminerList
) : ?int {
    $DB = \NDB_Factory::singleton()->database();

    $cbigExaminerName = $cbigExaminerList[$cbigExaminerID];
    $qpnExaminerID    = $DB->pselectOne(
        "SELECT examinerID
         FROM examiners
         WHERE full_name=:fullName",
        array('fullName' => $cbigExaminerName)
    );
    if (empty($qpnExaminerID)) {
        echo "\n\t\tThere is no examiner match in QPN for '$cbigExaminerName'.";
        return null;
    }
    return (int)$qpnExaminerID;
}

function getQPNVisitLabelByCBIGVisitLabel(
    string $cbigVisitLabel
) : ?string {
    $visitLabelMapping = [
        'Biospecimen01' => 'SampleCollection01',
        'Biospecimen02' => 'SampleCollection02',
        'Biospecimen03' => 'SampleCollection03',
    ];
    if (!isset($visitLabelMapping[$cbigVisitLabel])) {
        return null;
    }
    return $visitLabelMapping[$cbigVisitLabel];
}

function createTimePoint(
    string            $candID,
    string            $visit,
    string            $site,
    string            $battery,
    GuzzleHttp\Client $client,
    string            $baseURL,
    array             $headers
) : bool {
    $body = [
        'Meta' => [
            'CandID'  => $candID,
            'Visit'   => $visit,
            'Site'    => $site,
            'Battery' => $battery
        ]
    ];
    try {
        $response = $client->put(
            "$baseURL/candidates/$candID/$visit", [
                'headers' => $headers,
                'json'    => $body
            ]
        );
    } catch (Exception $e) {
        echo "\nException when calling Client->put($baseURL/candidates/$candID/$visit): ", $e->getMessage(), PHP_EOL;
        return false;
    }
    return true;
}

function handleGET(
    GuzzleHttp\Client $client,
    string            $url,
    array             $headers
) : array {
    // Get candidates
    try {
        $resp = $client->get(
            $url,
            [
                'headers' => $headers
            ]
        );
    } catch (Exception $e) {
        echo "\nException when calling Client->get($url): ", $e->getMessage(), PHP_EOL;
        return [];
    }
    $content = $resp->getBody()->getContents();
    $data    = json_decode($content, true);

    return $data;
}

function getCandidates(
    GuzzleHttp\Client $client,
    string            $url,
    array             $headers
) : array {
    $candidates = handleGET(
        $client,
        $url,
        $headers
    );

    return ($candidates['Candidates'] ?? []);
}
?>