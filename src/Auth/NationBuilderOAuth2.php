<?php
namespace tlshaheen\NationBuilder\Auth;

use tlshaheen\NationBuilder\Exceptions\OAuth2Exception;

/**
 * Class that implements necessary functionality to obtain an access token from a user
 *
 * @package     Auth
 * @author      Constant Contact
 */
class NationBuilderOAuth2
{
	public $clientslug;
    public $clientid;
    public $clientsecret;
    public $redirecturi;
    public $props;
    public $accesstoken;
    public $allparams;

    public function __construct($clientslug, $clientid, $clientsecret, $redirecturi = null, $restclient = null) {
    	$this->clientslug = $clientslug;
        $this->clientid = $clientid;
        $this->clientsecret = $clientsecret;
        $this->redirecturi = $redirecturi;
        $this->restclient = ($restclient) ? $restclient : new \OAuth2\Client($clientid, $clientsecret);;
    }

    /**
     * Get the URL at which the user can authenticate and authorize the requesting application
     *
     * @return string $url - The url to send a user to, to grant access to their account
     */
    public function getAuthorizationUrl() {
        return $this->restclient->getAuthenticationUrl('https://' . $this->clientslug . '.nationbuilder.com/oauth/authorize', $this->redirecturi);
    }

    public function setAccessToken($token) {	  
    	$this->accesstoken = $token;
		$this->restclient->setAccessToken($token);		
		return $token;
    }
    
    /**
    * Obtain an access token
    *
    * @param string $code - code returned from Constant Contact after a user has granted access to their account
    * @return array
    * @throws tlshaheen\NationBuilder\Exceptions\OAuth2Exception
    */
    public function getAccessToken($code) {
    	$client = $this->restclient;
    	
		// generate a token response
		$accesstokenurl = 'https://' . $this->clientslug . '.nationbuilder.com/oauth/token';
		$params = array('code' => $code, 'redirect_uri' => $this->redirecturi);
		$response = $client->getAccessToken($accesstokenurl, 'authorization_code', $params);
		
		// set the client token
		if (!isset($response['result']['access_token'])) {
			throw new OAuth2Exception($response['result']['error'] . ': ' . $response['result']['error_description']);
		} else {
			$token = $response['result']['access_token'];
			$client->setAccessToken($token);
			$this->accesstoken = $token;

            $this->allparams = json_encode($response['result']);

			return $token;		
		}
    }
    
}
