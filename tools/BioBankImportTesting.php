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


$count = 0;

foreach ($cbigCandidates as $cbigCandidate) {

    if ($count < 5){
        print_r('CAND!');
        echo '<pre>'; print_r($cbigCandidate); echo '</pre>';
        //print_r (gettype($cbigCandidate['DoB']));

        /* $paremsUrl = $cbig_shortened_url . '/candidate_parameters/?candID=' . $cbigCandidate['PSCID'] . '&identifier=' . $cbigCandidate['PSCID'];
        $cbigCandParems = handleGET(
            $cbigClient,
            $paremsUrl,
            $cbigHeaders
        );
        print_r('PAREMS!');
        echo '<pre>'; print_r($cbigCandParems); echo '</pre>';
        */


    }
    $count = $count + 1;
}


// Hard code a dummy candidate for test!
$firstTryCand = [];
$firstTryCand['PSCID'] = 'TOSI98765';
$firstTryCand['Dob'] = '1786-03-05';
$firstTryCand['SessionIDs'] = Array (
    '0' => '876',
    '1' => '9999');

print_r($firstTryCand);
//

$count2 = 0;

foreach ($cbigSpecimens as $cbigSpecimen) {

    if ($count2 < 5){
        echo '<pre>'; print_r($cbigSpecimen); echo '</pre>';
        //print_r (gettype($cbigCandidate['DoB']));
    }
    $count2 = $count2 + 1;
}

//
$count3 = 0;
foreach ($cbigContainers as $CbigContainer) {

    if ($count3 < 5){
        echo '<pre>'; print_r($CbigContainer); echo '</pre>';
        //print_r (gettype($cbigCandidate['DoB']));
    }
    $count3 = $count3 + 1;
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




















