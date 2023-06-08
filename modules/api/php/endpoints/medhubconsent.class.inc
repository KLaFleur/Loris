<?php


namespace LORIS\api\Endpoints;
use http\Env\Response; //delete??


set_include_path(get_include_path() . ":" . __DIR__);



// Are these necessary?
use LORIS\StudyEntities\Candidate\CandID;
use \Psr\Http\Message\ServerRequestInterface;
use \Psr\Http\Message\ResponseInterface;
use \LORIS\api\Endpoint;
use \LORIS\Data\Filters\HasAnyPermissionOrUserSiteMatch;
// ^^


class MedhubConsent extends Endpoint implements \LORIS\Middleware\ETagCalculator
{

    var $requestData;

    /**
     * Permission checks
     *
     * @param \User $user The requesting user
     *
     * @return boolean true if access is permitted
     */
    private function _hasAccess(\User $user)
    {
        return (
            $user->hasPermission('access_all_profiles') ||
            ($user->hasStudySite() && $user->hasPermission('data_entry'))
        );
    }

    /**
     * Return which methods are supported by this endpoint.
     *
     * @return array supported HTTP methods
     */
    protected function allowedMethods() : array
    {
        return [
            'PUT'
        ];
    }

   /**
     *  Candidates request handler
     *
     * @param string $method The HTTP request method of the request
     * @param array  $data   The data that was POSTed to the request
     */


    //TODO: do I need this??
    protected function supportedVersions() : array
    {
        return [
            "v0.0.3",
            "v0.0.4-dev",
        ];
    }

    /**
     * Handles a request starts with /candidates
     *
     * @param ServerRequestInterface $request The incoming PSR7 request
     *
     * @return ResponseInterface The outgoing PSR7 response
     */
    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user instanceof \LORIS\AnonymousUser) {
            return new \LORIS\Http\Response\JSON\Unauthorized();
        }

        if (!$this->_hasAccess($user)) {
            return new \LORIS\Http\Response\JSON\Forbidden();
        }

        $pathparts = $request->getAttribute('pathparts');

        if (count($pathparts) === 1) {
            switch ($request->getMethod()) {
                case 'PUT':
                    return $this->_handlePUT($request);
                default:
                    return new \LORIS\Http\Response\JSON\MethodNotAllowed(
                        $this->allowedMethods()
                    );
            }
        }

    }

    public function _handlePUT(ServerRequestInterface $request)
    {
        // $data  = $this->RequestData;
       // $data = $request->RequestData;
        $data = json_decode((string) $request->getBody(), true);
        print_r($data);

        if (empty($data)) {
            return new \LORIS\Http\Response\JSON\BadRequest(
               "Can't parse data" );
        }

        if (!isset($data['Token'])) {
             return new \LORIS\Http\Response\JSON\BadRequest(
               "There is no Token object in the PUT data" );
        }

        if (!isset($data['ConsentList'])) {
            return new \LORIS\Http\Response\JSON\BadRequest(
               "There is no Consent List object in the PUT data" );
        }

        //Tries to select CandID + entry date for the given token --> later throws and error and dies if nothing found
        $token = $data['Token'];
        $consentList = $data['ConsentList'];


        //Check that every consent is represented AND all fields are set
        $consentNames = ['INF','HR', 'GEN', 'CL', 'CQ', 'CS', 'CR', 'ATPSY','ORPS'];
        foreach($consentNames as $conName){
            if (!isset($consentList[$conName],$consentList[$conName]['Response'], $consentList[$conName]['Date'])){
                return new \LORIS\Http\Response\JSON\BadRequest(
               "Data Incomplete" );
            }
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/',$consentList[$conName]['Date'])){
                return new \LORIS\Http\Response\JSON\BadRequest(
               "Invalid Date Format for" . $conName . " expected date format is yyyy-mm-dd" );

            }
        }
        $loris  = $request->getAttribute('loris');
        $db     = $loris->getDatabaseConnection();
        $candidTimeUsed = $db->pselectRow(
            "SELECT CandidateID, EntryDate, AlreadyUsed
                FROM medhub_token
             WHERE Token = :to
                ",
            ['to' => $token]
        );
        $entryDate = $candidTimeUsed['EntryDate'];
        $candid = $candidTimeUsed['CandidateID'];
        if (empty($candidTimeUsed) || $candidTimeUsed['AlreadyUsed'] == 'TRUE'){
            return new \LORIS\Http\Response\JSON\BadRequest(
               "Data incomplete: token does not exist or has already been used" );

        }

        //Checks if token more than a ~month old
        if((time()-(60*60*24*30)) > strtotime($entryDate)){
            return new \LORIS\Http\Response\JSON\BadRequest(
               "Date check failed: Token has expired" );

        }


        foreach ($consentList as $conName => $conInfo){

            $consentArray = [];
            $consentArray['ConsentName'] = $conName;
            $consentArray['Status'] = $conInfo['Response'];
            $consentArray['DateGiven'] = $conInfo['Date'];

            try{
                $candID2 = new CandID($candid);
                \Candidate::singleton($candID2)->editConsentStatusFields($consentArray);
            }catch (LorisException $e){
                return new \LORIS\Http\Response\JSON\BadRequest(
                    "Consent data insertion failed" );
            }

        }

        //TODO: UNCOMMENT THIS TO DEPLOY!
        //Sets the already used marker in the token table
        /*
        $AlreadyUsed = array ('AlreadyUsed' => 'TRUE');
        $db->update('medhub_token', $AlreadyUsed,['CandidateID' => $candid] );*/



        return new \LORIS\Http\Response\JSON\Created(
                  [ "HTTP/1.1 201 Created" ] );
    }




    public function ETag(ServerRequestInterface $request) : string
    {
        return md5(json_encode($this->_handlePUT($request)->getBody()));
    }

}

if (isset($_REQUEST['PrintCandidates'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $fp   = fopen("php://input", "r");
        $data = '';
        while (!feof($fp)) {
            $data .= fread($fp, 1024);
        }
        fclose($fp);

        $obj = new MedhubConsent($_SERVER['REQUEST_METHOD'], json_decode($data, true));
    } else {
        $obj = new MedhubConsent($_SERVER['REQUEST_METHOD']);
    }
    print $obj->toJSONString();
}
