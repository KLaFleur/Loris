<?php

// require_once __DIR__ . '/../../../tools/generic_includes.php';
require_once __DIR__ . '/generic_includes.php';
use GuzzleHttp\Client;

// Set up API settings
$apiSettings = $config->getSetting('API');
$cbigBaseURL = $apiSettings['baseurl'];
$cbigCred    = [
    'username' => $apiSettings['username'],
    'password' => $apiSettings['password'],
];


// need to login to api next (not here yet)


//setup Guzzle client
try {
    $cbigClient = new Client(['base_uri' => $cbigBaseURL]);

} catch (Exception $e) {
    echo "\nException when initializing Guzzle Client with baseurl $cbigBaseURL: ", $e->getMessage(), PHP_OEL;
    die();
}
try {
    $cbigLoginResp = $cbigClient->post("$cbigBaseURL/login", ['json' => $cbigCred]);
} catch (Exception $e) {
    echo "\nException when calling Client->post($cbigBaseURL/login): ", $e->getMessage(), PHP_EOL;
    die();
}

$cbigToken = json_decode($cbigLoginResp->getBody()->getContents())->token ?? null;

$cbigHeaders = [
    'Authorization' => "Bearer $cbigToken"
];

// Grab CBIG candidates
$cbigCandidates = getCandidates(
    $cbigClient,
    //"$cbigBaseURL/candidates/ext/",
    "$cbigBaseURL/candidates/ext",
    $cbigHeaders
);
// Grab CBIG specimens
$cbig_shortened_url = str_replace('/api/v0.0.3-dev', '', $cbigBaseURL);
$cbigSpecimens      = handleGET(
    $cbigClient,
    "$cbig_shortened_url/biobank/specimenendpoint/",
    $cbigHeaders
);

$cbigContainers = handleGET(
    $cbigClient,
    "$cbig_shortened_url/biobank/containerendpoint/",
    $cbigHeaders
);

//grab options!
$cbigOptions = handleGET(
    $cbigClient,
    "$cbig_shortened_url/biobank/optionsendpoint/",
    $cbigHeaders
);

/*
$count = 0;

foreach ($cbigCandidates as $cbigCandidate) {

	if ($count < 15){
        print_r('CAND!');
		echo '<pre>'; print_r($cbigCandidate); echo '</pre>';
        //print_r (gettype($cbigCandidate['DoB']));




	}
    $count = $count + 1;
}

//Mke useful array!!
//$cbigCandidates1 = json_decode($cbigCandidates, true)['Candidates'];
print_r($cbigCandidates['150633']);
*/

/*
echo('specimens');

// Printing out some specimens and Containers!
$count2 = 0;

foreach ($cbigSpecimens as $cbigSpecimen) {

    if ($count2 < 25){
        echo '<pre>'; print_r($cbigSpecimen); echo '</pre>';
        //print_r (gettype($cbigCandidate['DoB']));
    }
    $count2 = $count2 + 1;
}

//
*/

//echo('containers!!!\n');
//
//$count3 = 0;
//foreach ($cbigContainers as $CbigContainer) {
//
//    if ($count3 < 5){
//        echo '<pre>'; print_r($CbigContainer); echo '</pre>';
//        //print_r (gettype($cbigCandidate['DoB']));
//    }
//    $count3 = $count3 + 1;
//}

printf(sizeof($cbigContainers));



// Hard code a dummy candidate for test!
$firstTryCand = [];
$firstTryCand['Project'] = 'QPN' ;
//$firstTryCand['CandID'] = '1433302';
$firstTryCand['PSCID'] = 'TOSI9870005';
//$firstTryCand['EDC'] = '';
$firstTryCand['DoB'] = '1986-03-05';
$firstTryCand['Sex'] = 'Male';
$firstTryCand['Site'] = 'CRU-MNI';
//

//$firstTryCand['ExtStudyID'] = '87fbwq9ef8ygwb';
/*$firstTryCand['SessionIDs'] = Array (
    '0' => '876',
    '1' => '9999');
*/

$candObj1 = [];
$candObj1['Candidate'] = $firstTryCand;
$cand1 = json_encode($candObj1);

// Try posting candidate
/*


//$response = new \LORIS\Http\Response();
$response = $cbigClient->post(
    "$cbigBaseURL/candidates/", [
        'headers' => $cbigHeaders,
        'json'    => $candObj1
    ]
);

*/

//Try making timepoint for our dummy candidate
$candIDTimep = '113866';
$visitLabT = 'Biospecimen03';
$siteNameT = 'Montreal Neurological Institute (QPN)';
$candidateSubprojectT = 'Disease';
//$CBIG_Client already exists
//baseurl exists
//headers exist


//Creat timepoint w/ above info!!

//$success = createTimePoint(
//    $candIDTimep,
//    $visitLabT,
//    $siteNameT,
//    $candidateSubprojectT,
//    $cbigClient,
//    $cbigBaseURL,
//    $cbigHeaders
//);


$new_visit = handleGET(
    $cbigClient,
    "$cbigBaseURL/candidates/$candIDTimep/$visitLabT/",
    $cbigHeaders
);

 //* Container Stuff!
//echo ($success);
// projectIds could be a problem!!

$dummyContainer = array (
    //'id' => '43723',
    'barcode' => 'todayBarcode1010',
    //'specimenId' => '10000112',
    'typeId' => '16',
    'dimensionId' => '',
    'temperature' => -'80',
    'statusId' => '1',
    //'projectIds' => [],
    'projectIds' => Array
        (
            '0' => '16'
        ),

    'shipmentBarcodes' => [],

    'centerId' => '10',
    //'parentContainerId' => null,
    'childContainerIds' => [],
    'coordinate' => null,
    'lotNumber' => '',
    'expirationDate' => '',
    'comments' => ''
);

print_r($dummyContainer);


json_encode($dummyContainer);


/*
 * posting container
 try {
    $responseCont = $cbigClient->post(
        "$cbig_shortened_url/biobank/containerendpoint", [
            'headers' => $cbigHeaders,
            'json'    => [$dummyContainer]
        ]
    );

} catch(Exception $e) {
    printf('were not here right?');
    printf($e->getResponse() -> getBodsqly()-> getContents());
    die();
}*/


echo('did we get here????');


 //original dummy!!
$dummySpecimen = array (
   // 'id' => '10000112' ,
   //'containerId' => '43723',
    'container'   => $dummyContainer,
    'typeId' => '4',
    'quantity' => '1000.000' ,
    'unitId' => '1' ,
    'fTCycle' => '0'  ,
    'parentSpecimenIds' => [] ,
    //'candidateId' => '113866'  ,
    //'candidateAge' => '36'  ,
    'sessionId' => '2' ,
    'poolId' => '' ,
    'collection' => Array
        (
            'protocolId' => '21' ,
            'centerId' => '10' ,
            'examinerId' => '3' ,
            'date' => '2017-11-02' ,
            'time' => '00:00' ,
            'comments' => '',
            'data' => Array
                (
                    '17' => '0' ,
                    '57' => '2017-11-02'
                ),

            'quantity' => '1000.000' ,
           'unitId' => '1'
        )

);


// Dummy 2
//$dummySpecimen = array (
//    // 'id' => '10000112' ,
//    //'containerId' => '43723',
//    'container'   => $dummyContainer,
//    'typeId' => '4',
//    'quantity' => '1000.000' ,
//    'unitId' => '1' ,
//    'fTCycle' => '0'  ,
//    'parentSpecimenIds' => [] ,
//    'candidateId' => '113866'  ,
//    'candidateAge' => '36'  ,
//    'sessionId' => '2' ,
//    'poolId' => ''
////    'collection' => Array
////    (
////        'protocolId' => '21' ,
////        'centerId' => '10' ,
////        'examinerId' => '1' ,
////        'date' => '2019-11-02' ,
////        'time' => '00:00' ,
////        'comments' => '',
////        'data' => [],
////            /*Array
////        (
////            '17' => '0' ,
////            '57' => '2019-11-02'
////        ),*/
////
////
////        'quantity' => '1000.000' ,
////        'unitId' => '1'
////    )
//
//);


print(getType($dummySpecimen));
$dummySpec1 = [];
$dummySpec1['Specimen'] = $dummySpecimen;
json_encode($dummySpecimen);

$responseSpec = $cbigClient->post(
    "$cbig_shortened_url/biobank/specimenendpoint", [
        'headers' => $cbigHeaders,
        'json'    => [$dummySpecimen]
    ]
);

echo($responseSpec->getStatusCode());

if ($responseSpec->getStatusCode() != 200) {
    echo($responseSpec->getBody()->__toString());
}


/*
$count4 = 0;
foreach ($cbigOptions as $cbigOption) {

    if ($count4 < 5){
        echo '<pre>'; print_r($cbigOption); echo '</pre>';
        //print_r (gettype($cbigCandidate['DoB']));
    }
    $count4 = $count4 + 1;
}
*/
//





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
        print_r($response);
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



















