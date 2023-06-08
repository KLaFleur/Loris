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


class MedhubTokenValidator extends Endpoint implements \LORIS\Middleware\ETagCalculator
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
            'GET'
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
                case 'GET':
                    return $this->_handleGET($request);
                default:
                    return new \LORIS\Http\Response\JSON\MethodNotAllowed(
                        $this->allowedMethods()
                    );
            }
        }

    }

    public function _handleGET(ServerRequestInterface $request)
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

        //Tries to select CandID + entry date for the given token --> later throws and error and dies if nothing found
        $token = $data['Token'];

        $loris  = $request->getAttribute('loris');
        $db     = $loris->getDatabaseConnection();
        $tokenExists = '';

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
               "Data incomplete: token does not exist" );
        }
        if ($candidTimeUsed['AlreadyUsed'] == 'TRUE'){
            return new \LORIS\Http\Response\JSON\BadRequest(
                "Data incomplete: the provided token has already been used" );
        }

        //Checks if token more than a ~month old
        if((time()-(60*60*24*30)) > strtotime($entryDate)){
            return new \LORIS\Http\Response\JSON\BadRequest(
               "Date check failed: Token has expired" );
        }

//        $tokenExists = True;
//        $this->_cache = new \LORIS\Http\Response\JsonResponse(
//            ['TokenExists' => 'True']
//        );
//
        return new \LORIS\Http\Response\JSON\Created(
                  [ "HTTP/1.1 201 Created" ] );
    }




    public function ETag(ServerRequestInterface $request) : string
    {
        return md5(json_encode($this->_handleGET($request)->getBody()));
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

