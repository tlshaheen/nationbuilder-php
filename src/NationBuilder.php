<?php
namespace tlshaheen\NationBuilder;

//This page shouldn't cause a timeout; sometimes we will sleep so we don't hit a rate limit
set_time_limit(0);

use tlshaheen\NationBuilder\Exceptions\NationBuilderException;

class NationBuilder
{

    public $fetchurl,
        $accesstoken,
        $clientslug,
        $restclient,
        $responseHeaders
    ;

    public function __construct($clientslug, $clientid, $clientsecret, $token)
    {
        $this->setFetchUrl($clientslug);
        $this->accesstoken = $token;
        $this->clientslug = $clientslug;

        $restclient = new \tlshaheen\NationBuilder\Auth\NationBuilderOAuth2($clientslug, $clientid, $clientsecret);
        $this->restclient = $restclient->restclient;
        $this->restclient->setAccessToken($token);
    }

    public function setFetchUrl($clientslug)
    {
        $this->fetchurl = 'https://' . $clientslug . '.nationbuilder.com';
        return $this->fetchurl;
    }

    public function getFetchUrl()
    {
        return $this->fetchurl;
    }

    /**
     *
     *
     * @author tlshaheen
     */
    public function fetchData(
        $endpointurl,
        $params = [],
        $httpmethod = null,
        $httpheaders = null,
        $formcontenttype = null
    ) {
        if (empty($params)) {
            $params = [];
        }

        $fullurl = '';
        $fetchurl = $this->getFetchUrl();
        if (strpos($endpointurl, $this->getFetchUrl()) === false) {
            $fullurl = $this->getFetchUrl();
        }
        if (strpos($endpointurl, 'api/v1') === false) {
            $fullurl .= '/api/v1';
        }
        if (substr($endpointurl, 0, 1) != '/') {
            $fullurl .= '/';
        }
        $fullurl .= $endpointurl;

        if ($httpmethod == 'POST' || $httpmethod == 'PUT' || $httpmethod == 'DELETE') {
            $params['access_token'] = $this->accesstoken;
            $params = json_encode($params);

            $httpheaders['Content-Type'] = 'application/json';

            if (substr($fullurl, -1) == '?') {
                $fullurl = substr($fullurl, 0, mb_strlen($fullurl) - 1);
            }
            parse_str(parse_url($fullurl, PHP_URL_QUERY), $existingparams);
            if (sizeof($existingparams)) {
                $fullurl .= '&';
            } else {
                $fullurl .= '?';
            }
            $fullurl .= "access_token=" . $this->accesstoken;
        }

        //We want to use fetch()'s defaults, so don't pass a setting if it hasn't been set by the user
        if (isset($params) && isset($httpmethod) && isset($httpheaders) && isset($formcontenttype)) {
            $response = $this->restclient->fetch($fullurl, $params, $httpmethod, $httpheaders, $formcontenttype);
        } else {
            if (isset($params) && isset($httpmethod) && isset($httpheaders)) {
                $response = $this->restclient->fetch($fullurl, $params, $httpmethod, $httpheaders);
            } else {
                if (isset($params) && isset($httpmethod)) {
                    $response = $this->restclient->fetch($fullurl, $params, $httpmethod);
                } else {
                    if (isset($params)) {
                        $response = $this->restclient->fetch($fullurl, $params);
                    } else {
                        $response = $this->restclient->fetch($fullurl);
                    }
                }
            }
        }

        //Store all headers, and if possible, separate the rate limits
        if (isset($response['headers'])) {
            $this->responseHeaders = $response['headers'];

            if (
                (isset($this->responseHeaders['X-Ratelimit-Remaining']) && $this->responseHeaders['X-Ratelimit-Remaining'] < 1)
                || (isset($this->responseHeaders['Nation-Ratelimit-Remaining']) && $this->responseHeaders['Nation-Ratelimit-Remaining'] < 1)
            ) {
                $ex = new NationBuilderException('Error: Rate Limited', 429);
                throw $ex;
            }
        }

        if (isset($response['result'])) {
            return $response['result'];
        }
        //die("<pre>" . print_r($response,1) . "</pre>");
        return $response;

    }

    /**
     * Push a "contact" between two People or a broadcaster and a person
     * See the API for all options - http://nationbuilder.com/contacts_api
     *
     * @author tlshaheen
     */
    public function createContact($recipientid, $contact)
    {
        if (!isset($contact['contact'])) {
            $contact = array('contact' => $contact);
        }
        $response = $this->fetchData('people/' . $recipientid . '/contacts', $contact, 'POST');

        if (isset($response['contact'])) {
            return $response['contact'];
        } else {
            return $response;
        }
    }

    /**
     * Retrieves all the contact types for the account
     *
     * @author tlshaheen
     */
    public function getContactTypes($per_page = 100, $next = false)
    {
        \Log::info('-----NationBuilder Customer ID #' . $this->clientslug . ' - Running getContactTypes and next: ' . $next);
        if ($next) {
            $response = $this->fetchData($next, null);
        } else {
            $data = array('limit' => $per_page);
            $response = $this->fetchData('settings/contact_types', $data, 'GET');
        }

        return $response;
    }

    /**
     * Get all the contacts for a person
     *
     * @param int  $perPage
     * @param null $nextUrl
     *
     * @return array
     * @author TLS
     * @date   7-19-2016
     */
    public function retrieveContacts($personId = null, $nextUrl = null, $perPage = 1000)
    {
        if (!$nextUrl) {
            $data = [
                'limit' => $perPage
            ];
            $response = $this->fetchData('people/' . $personId . '/contacts', $data, 'GET');
        } else {
            //If we are passed a next URL, just hit that URL - no extra criteria needed
            $response = $this->fetchData($nextUrl, null);
        }

        return $response;
    }

    /**
     * Searchs for a contact matching the search parameters. Returns the FIRST match.
     *
     * @param $searchParams
     *          Optional parameters
     *          type_id
     *          method
     *          sender_id
     *          recipient_id
     *          status
     *          broadcaster_id
     *          note
     *          capital_in_cents
     *          created_at
     *
     * @return array
     * @author tlshaheen
     * @date   7-19-2016
     */
    public function findContact($personId, $searchParams)
    {
        //NationBuilder does not have a Contact search endpoint
        $contactInfo = null;
        $first = true;
        $next = null;
        while (!empty($contacts['next']) || $first == true) {
            $first = false;
            //Get the contacts
            $contacts = $this->retrieveContacts($personId, $next);
            if (!empty($contacts['results'])) {
                foreach ($contacts['results'] as $contactResult) {
                    //By default, this result is the match
                    //If we find that one of the fields is not a match, then we'll continue to the next result
                    $exactMatch = true;
                    foreach ($searchParams as $searchParamField => $searchParamValue) {
                        if (!isset($contactResult[$searchParamField]) || $contactResult[$searchParamField] != $searchParamValue) {
                            //One of the search params does not match
                            //Continue to the next result
                            $exactMatch = false;
                            break;
                        }
                    }
                    if (!$exactMatch) {
                        continue;
                    } else {
                        //We found a match, return the result
                        return $contactResult;
                    }
                }
            }
            if (!empty($contacts['next'])) {
                $next = $contacts['next'];
            }
        }
        return null;
    }

    /**
     * Find contact made by author_id
     * @param $searchParams
     *
     * @return null
     * @author tlshaheen
     * @date   8-10-2016
     */
    public function findContactMade($searchParams)
    {
        $contactInfo = null;
        $first = true;
        $next = null;
        while (!empty($contacts['next']) || $first == true) {
            $first = false;
            //Get the contacts made
            $contacts = $this->fetchData('contacts', $searchParams, 'GET');
            if (!empty($contacts['results'])) {
                foreach ($contacts['results'] as $contactResult) {
                    //By default, this result is the match
                    //If we find that one of the fields is not a match, then we'll continue to the next result
                    $exactMatch = true;
                    foreach ($searchParams as $searchParamField => $searchParamValue) {
                        //Convert timestamps to epoch time
                        if ($searchParamField == 'created_at') {
                            $contactResult[$searchParamField] = strtotime($contactResult[$searchParamField]);
                            $searchParamValue = strtotime($searchParamValue);
                        }
                        if (!array_key_exists($searchParamField, $contactResult) || $contactResult[$searchParamField] != $searchParamValue) {
                            //One of the search params does not match
                            //Continue to the next result
                            $exactMatch = false;
                            break;
                        }
                    }
                    if (!$exactMatch) {
                        continue;
                    } else {
                        //We found a match, return the result
                        return $contactResult;
                    }
                }
            }
            //Sleep for 1 second to prevent rate limits
            usleep(1000000);
        }
        return null;
    }

    /**
     * Create a contact type and return the ID and name
     *
     * @author tlshaheen
     */
    public function createContactType($typename)
    {
        $type = array('contact_type' => array('name' => $typename));

        $response = $this->fetchData('settings/contact_types', $type, 'POST');

        if (isset($response['contact_type'])) {
            return $response['contact_type'];
        } else {
            return $response;
        }
    }

    /**
     * Add a single person, or a group of people, to a given list
     *
     * @author tlshaheen
     */
    public function addToList($listid, $listmembers)
    {
        if (empty ($listmembers)) {
            return false;
        }
        if (!is_array($listmembers)) {
            $listmembers = [$listmembers];
        }
        if (!isset($listmembers['people_ids'])) {
            $listmembers = ['people_ids' => $listmembers];
        }

        $response = $this->fetchData('lists/' . $listid . '/people', $listmembers, 'POST');

        return $response;
    }

    /**
     * Add tags to a given person
     *
     * @author tlshaheen
     */
    public function addTags($personid, $tagnames)
    {
        $tagging = array('tagging' => array('tag' => $tagnames));
        $response = $this->fetchData('people/' . $personid . '/taggings', $tagging, 'PUT');

        if (isset($response['tagging'])) {
            return $response['tagging'];
        } else {
            return $response;
        }
    }

    /**
     * Remove tags for a given person.
     *
     * @return boolean Returns true on success, false on failure
     * @author tlshaheen
     */
    public function removeTags($personid, $tagnames)
    {
        //Make sure all tags are strings
        $tags = array();
        foreach ($tagnames as $tagname) {
            $tags[] = strval($tagname);
        }
        $tagging = array('tagging' => array('tag' => $tags));
        $response = $this->fetchData('people/' . $personid . '/taggings/', $tagging, 'DELETE');

        if (empty($response)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get all the lists for a nation
     *
     * @param int  $perPage
     * @param null $nextUrl
     *
     * @return array
     * @author TLS
     * @date   7-19-2016
     */
    public function retrieveLists($nextUrl = null, $perPage = 1000)
    {
        if (!$nextUrl) {
            $data = [
                'limit' => $perPage
            ];
            $response = $this->fetchData('lists', $data, 'GET');
        } else {
            //If we are passed a next URL, just hit that URL - no extra criteria needed
            $response = $this->fetchData($nextUrl, null);
        }

        return $response;
    }

    /**
     * Searchs for a list matching the search parameters. Returns the FIRST match.
     *
     * @param $searchParams
     *          Optional parameters
     *          id
     *          name - searches for the exact name match
     *          slug - searches for the exact slug match
     *          -author_id
     *          -count
     *
     * @return array
     * @author tlshaheen
     * @date   7-19-2016
     */
    public function findList($searchParams)
    {
        //NationBuilder does not have a List search endpoint
        //Instead, they only offer a index of lists. So we need to iterate over all the lists and try to find a match for our search params
        $listInfo = null;
        $first = true;
        $next = null;
        while (!empty($lists['next']) || $first == true) {
            $first = false;
            //Get the lists
            $lists = $this->retrieveLists($next);
            if (!empty($lists['results'])) {
                foreach ($lists['results'] as $listResult) {
                    foreach ($searchParams as $searchParamField => $searchParamValue) {
                        if (array_key_exists($searchParamField, $listResult) && $listResult[$searchParamField] == $searchParamValue) {
                            //We found a match, return the result
                            return $listResult;
                        }
                    }
                }
            }
            if (!empty($lists['next'])) {
                $next = $lists['next'];
            }
        }
        return null;
    }

    /**
     * Delete a given list
     *
     * @author tlshaheen
     */
    public function deleteList($listid)
    {
        $response = $this->fetchData('lists/' . $listid, array(), 'DELETE');

        if (isset($response['results'])) {
            return $response;
        } else {
            return $response;
        }
    }

    /**
     * Create a blank list on NB
     *
     * @param mixed $data An associate array of query parameters and values to append to the rest
     *                    Required parameters:
     *                    name - the name of the list
     *                    slug - a unique identifier for the list
     *                    author_id - the author of the list
     *                    Optional parameters:
     *                    sort_order - for example, oldest_first
     *
     * @author tlshaheen
     */
    public function createList($data)
    {
        if (!isset($data['list'])) {
            $data = array('list' => $data);
        }
        $response = $this->fetchData('lists', $data, 'POST');

        if (isset($response['list_resource'])) {
            return $response['list_resource'];
        } else {
            return $response;
        }
    }

    /**
     * Update a list on NB
     *
     * @param $listId
     * @param $data
     *          Required parameters:
     *          name - the name of the list
     *          slug - a unique identifier for the list
     *          author_id - the author of the list
     *
     * @return bool
     * @author tlshaheen
     * @date   7-19-2016
     */
    public function updateList($listId, $data)
    {
        if (!isset($data['list'])) {
            $data = [
                'list' => $data,
            ];
        }
        $response = $this->fetchData('lists/' . $listId, $data, 'PUT');
        if (isset($response['list_resource'])) {
            return $response['list_resource'];
        } else {
            return $response;
        }
    }

    /**
     * Get the access token's resource owner's representation.
     *
     * @author tlshaheen
     */
    public function getMe()
    {
        $response = $this->fetchData('people/me');
        if (isset($response['person'])) {
            return $response['person'];
        } else {
            return $response;
        }
    }

    /**
     * Get a person's details based on their ID
     *
     * @author tlshaheen
     */
    public function getPerson($personid, $external = false)
    {
        if ($external) {
            $personparam = '?id_type=external';
        } else {
            $personparam = '';
        }

        $response = $this->fetchData('people/' . $personid . $personparam);
        if (isset($response['person'])) {
            return $response['person'];
        } else {
            return $response;
        }

    }

    /**
     * Match a single person based on certain criteria
     *
     * @param mixed $criteria An associative array of query parameters and values to append to the request.
     *                        Allowed parameters include:
     *                        email - Email address
     *                        first_name - First Name
     *                        last_name - Last Name
     *                        phone - Phone number
     *                        mobile - Mobile number
     *
     * @return mixed Returns an empty array if a person was not found, otherwise, returns the normal Person array
     * @author tlshaheen
     */
    public function matchPerson($criteria)
    {
        $response = $this->fetchData('people/match', $criteria);

        if (!empty($response['person'])) {
            return $response['person'];
        } else {
            return $response;
        }
    }

    /**
     * Find a set of people who have certain attributes
     *
     * @param mixed $criteria An associative array of query parameters and values to append to the request.
     *                        Allowed parameters include:
     *                        next - if this is set, this is the URL to use to get the next set of previous query
     *                        results. The other criteria will be ignored if this is passed. first_name - First Name
     *                        last_name - Last Name city - City state - State sex - Sex (M/F) birthdate - Birthdate
     *                        updated_since - People updated since the given date (Can be in any valid date/time
     *                        format) with_mobile - Only people with mobile phone numbers custom_values - "match custom
     *                        field values. It takes a nested format, e.g. {"custom_values": {"my_field_slug":
     *                        "abcd"}}. In the query string this parameter would have to be encoded as
     *                        custom_values%5Bmy_field_slug%5D=abcd."
     * @param int   $page     Page number (default: 1) - set to 'all' to retrieve ALL matches in one array (no
     *                        pagination)
     * @param int   $perpage  Number of results to show per page (default: 10; max: 100)
     *
     * @return mixed Returns an associate array - page (current page), total_pages, per_page, total, and results.
     *               ['results'] will be empty and ['total'] will be 0 if no people were found.
     * @author tlshaheen
     */
    public function findPeople($criteria, $perpage = null)
    {
        if (!isset($criteria['next']) || !$criteria['next']) {
            //NB takes page and per_page as part of the url parameters
            //If they aren't passed, page defaults to 1 and per_page defaults to 10
            if ($perpage !== null) {
                $criteria['limit'] = $perpage;
                $criteria['per_page'] = $perpage;
            }

            //Convert updated_since to how NB stores their date
            //Ex: 2014-06-30T22:45:54-04:00
            if (isset($criteria['updated_since'])) {
                $updatedsince = new \DateTime($criteria['updated_since']);
                $updatedsince = $updatedsince->format('Y-m-d\TH:i:sP');
                $criteria['updated_since'] = $updatedsince;
            }
            \Log::info('NationBuilder Pull findPeople going to search: criteria: ' . json_encode($criteria));
            return $this->fetchData('people/search', $criteria);
        } else {
            //If we are passed a next URL, just hit that URL - no extra criteria needed
            return $this->fetchData($criteria['next'], null);
        }
    }

    /**
     * Create a new person
     *
     * @param mixed $person An associative array of the data to create the person with.
     *                      For all optional parameters, please see NB documentation
     *                      Required parameters are:
     *                      first_name - First Name
     *                      last_name - Last Name
     *                      email - Email address
     *                      OR
     *                      phone - Phone number
     *                      Add an address in a sub-array of ['registered_address']
     *
     * @return mixed Returns an associate array containing all the person's details on NB
     * @author tlshaheen
     */
    public function createPerson($person)
    {
        if (!array_key_exists('person', $person)) {
            $person = array('person' => $person);
        }
        if (!isset($person['person']['email']) && !isset($person['person']['email1']) && !isset($person['person']['phone']) && !isset($person['person']['mobile'])) {
            throw new NationBuilderException('Error: You cannot create a person without an email address or phone number.');
        }

        $response = $this->fetchData('people', $person, 'POST');

        if (isset($response['person'])) {
            return $response['person'];
        } else {
            return $response;
        }
    }

    /**
     * Update a person
     *
     * @param mixed $personid The ID of the person you want to update
     * @param mixed $person   An associative array of the data to update the person with.
     *                        For all optional parameters, please see NB documentation
     *
     * @return mixed Returns an associate array containing all the person's details on NB
     * @author tlshaheen
     */
    public function updatePerson($personid, $person)
    {
        if (!ctype_digit(strval($personid))) {
            throw new NationBuilderException('Error: A person ID is required to update a person.');
        }

        if (!array_key_exists('person', $person)) {
            $person = array('person' => $person);
        }

        $response = $this->fetchData('people/' . $personid, $person, 'PUT');

        if (isset($response['person'])) {
            return $response['person'];
        } else {
            return $response;
        }
    }

    /**
     * Push a person's data - it will update a person if a match is found EXCLUSIVELY by email address or external id
     * It will create a person if no match is found
     *
     * @param mixed $person An associative array of the data to create the person with.
     *                      For all optional parameters, please see NB documentation
     *                      Required parameters are:
     *                      first_name - First Name
     *                      last_name - Last Name
     *                      email - Email address
     *                      OR
     *                      external_id - External ID
     *
     * @return mixed Returns an associate array containing all the person's details on NB
     * @author tlshaheen
     */
    public function pushPerson($person)
    {
        if (!array_key_exists('person', $person)) {
            $person = array('person' => $person);
        }
        /*if (!isset($person['person']['email']) && !isset($person['person']['external_id'])) {
            throw new NationBuilderException('Error: You cannot try to push a person without an email address or external id.');
        }*/
        $response = $this->fetchData('people/push', $person, 'PUT');

        if (isset($response['person'])) {
            return $response['person'];
        } else {
            return $response;
        }
    }

    /**
     * Delete a person
     *
     * @param mixed $personid The ID of the person you want to delete
     *
     * @return boolean Returns true
     * @author tlshaheen
     */
    public function deletePerson($personid)
    {
        if (!ctype_digit(strval($personid))) {
            throw new NationBuilderException('Error: A person ID is required to update a person.');
        }
        $response = $this->fetchData('people/' . $personid, array(), 'DELETE');

        return true;
    }

    /**
     * Formats a phone number for storing in a DB - comes out as +17035551234
     *
     * @author prstoddart
     */
    public function formatPhoneForStorage($inputphone)
    {
        $outputphone = "";

        $phone = preg_replace("/[^0-9]/", "", $inputphone);
        if (strlen($phone) == 10) {
            $outputphone = "+1" . $phone;
        } elseif (strlen($phone) == 11 && substr($phone, 0, 1) == "1") {
            $outputphone = "+" . $phone;
        }

        return $outputphone;
    }

    /**
     * Formats a phone number for storing in NationBuilder - comes out as 7035551234
     *
     * @author prstoddart
     */
    public function formatPhoneForNationBuilder($phone, $us = true)
    {
        if ($us) {
            //If phone was prefixed with +1, remove it
            $phone = str_replace('+1', '', $phone);
            //Remove any remaining non-numbers
            $phone = preg_replace("/[^0-9]/", "", $phone);
        }

        return $phone;
    }

    public function addSocialCapital($personId, $params)
    {
        if (empty($params['capital'])) {
            $params['capital'] = $params;
        }
        return $this->fetchData('people/' . $personId . '/capitals', [
            $params
        ], 'POST');
    }

    /**
     * @return array
     * @author TLS
     * @date   7-19-2016
     */
    public function retrieveCount()
    {
        $response = $this->fetchData('people/count', [], 'GET');
        if (isset($response['people_count'])) {
            return $response['people_count'];
        } else {
            return $response;
        }
    }

    /**
     * @param null $nextUrl
     * @param int  $perPage
     *
     * @return array
     * @author TLS
     * @date   7-22-2016
     */
    public function retrievePeople($nextUrl = null, $perPage = 100)
    {
        if (!$nextUrl) {
            $data = [
                'limit' => $perPage
            ];
            $response = $this->fetchData('people', $data, 'GET');
        } else {
            //If we are passed a next URL, just hit that URL - no extra criteria needed
            $response = $this->fetchData($nextUrl, null);
        }

        return $response;
    }

    /**
     * @param     $event
     * @param     $url
     * @param int $version
     *
     * @return array
     * @author TLS
     * @date   7-22-2016
     */
    public function createWebhook($event, $url, $version = 4)
    {
        $webhook = [
            'webhook' => [
                'version' => $version,
                'url' => $url,
                'event' => $event,
            ],
        ];
        $response = $this->fetchData('webhooks', $webhook, 'POST');
        if (!empty($response['webhook'])) {
            return $response['webhook'];
        }
        return $response;
    }

    /**
     * NationBuilder does not have a Webhook search endpoint. Instead, they only offer a index of webhooks.
     * So we need to iterate over all the webhooks and try to find a match for our search params
     *
     * @param $event
     * @param $url
     * @param $version
     *
     * @return null
     * @author tlshaheen
     * @date   8-12-2016
     */
    public function findWebhook($event, $url, $version) {
        $webhookInfo = null;
        $first = true;
        $next = null;
        while (!empty($webhooks['next']) || $first == true) {
            $first = false;
            //Get the webhooks
            $webhooks = $this->fetchData('webhooks', ['limit' => 100], 'GET');
            if (!empty($webhooks['results'])) {
                foreach ($webhooks['results'] as $webhookResult) {
                    //If version, url, and event all match, then this is the webhook we've been searching for
                    if (
                        $webhookResult['version'] == $version
                        && $webhookResult['url'] == $url
                        && $webhookResult['event'] == $event
                    ) {
                        return $webhookResult;
                    }
                }
            }
        }
        return null;
    }
}