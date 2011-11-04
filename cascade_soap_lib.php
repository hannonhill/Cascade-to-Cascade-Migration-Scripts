<?php

/*
 * PHP SOAP Library for Cascade
 *
 * Earl Fogel, September 2009
 */

class CascadeSoapClient {
    var $url;
    var $username;
    var $password;
    var $client;
    var $success;
    var $response;
    var $asset;

    function connect() {
      try {
	$this->client = new SoapClient($this->url, array('trace' => 1));
      } catch (Exception $e) {
	/* The request will throw an exception when parameters do
	 * not conform to the WSDL. This will *not* catch errors
	 * in the parameters themselves */
	print("Connect error: " . $e->getMessage() . "\n");
	exit;
      }
    }


    /* Read an asset from Cascade with web services.
     * Create an associative array that we will pass to the PHP
     * SOAP functions. The structure of this array is determined
     * by the Cascade WSDL specification. PHP interprets the array
     * and creates appropriate XML to pass to the Web Services
     */
    function read($id) {

      if (isset($id->id)) {
	$identifier = array(
	    'type' => $id->type,
	    'id'   => $id->id,
	  );
#      } else if ($id->type == 'site') {
#	$identifier = array(
#	    'type' => $id->type,
#	    'path' => array( 'path' => $id->path, 'siteName' => '' ),
#	  );
      } else if (isset($id->siteId)) {
	$identifier = array(
	    'type' => $id->type,
	    'path' => array( 'path' => $id->path, 'siteId' => $id->siteId ),
	  );
      } else {
	$identifier = array(
	    'type' => $id->type,
	    'path' => array( 'path' => $id->path, 'siteName' => $id->siteName ),
	  );
      }
      $params = array (
	  'authentication' => array(
	    'username' => $this->username,
	    'password' => $this->password,
	  ),
	  'identifier' => $identifier,
      );
      //print_r($params);

      try {
	/* Pass the parameters to the PHP SOAP client. */
	$this->success = false;
	$this->response = $this->client->read($params);

	if ($this->response->readReturn->success == 'true') {
	    $type = $id->type;
	    $this->asset = $this->response->readReturn->asset;
	    clean_asset($this->asset);
	    $this->success = true;
	    #echo "Success\n";
	}

      } catch (Exception $e) {
	/* The request will throw an exception when parameters do
	 * not conform to the WSDL. This will *not* catch errors
	 * in the parameters themselves */
	//print("Read failed to conform to the WSDL.\n");
	//print($client->__getLastResponse() . "\n");
      }
      if ($this->success != true) {
	$this->response = $this->client->__getLastResponse() . "\n";
      }
    }


    /* Add an asset (page, file, folder, ...) to Cascade with web services.
     * The asset's information should be stored in an associative array, $asset.
     */
    function create($asset) {

      /* Construct the parameters in the same way that we
       * constructucted them for the folder, though creating
       * a page requires a bit more information
       */
      $params =
	array (
	  'authentication' => array(
	    'username' => $this->username,
	    'password' => $this->password,
	  ),
	  'asset' => $asset
	);
      //print_r($params);

      try {
	$this->success = false;
	$this->response = $this->client->create($params);
	if ($this->response->createReturn->success == 'true') {
	    $this->success = true;
	    $this->createdAssetId =
		$this->response->createReturn->createdAssetId;
	}

      } catch (Exception $e) {
	//print("Creation request failed to conform to the WSDL.\n");
	//print($client->__getLastResponse() . "\n");
      }
      if ($this->success != true) {
	$this->response = $this->client->__getLastResponse() . "\n";
      }
    }



    /* Update an existing asset (page, file, ...) in Cascade with web services.
     * The asset's information should be stored in an associative array, $asset.
     * --- not tested ---
     */
    function edit($asset) {

      /* Construct the parameters in the same way that we
       * constructucted them for the folder, though creating
       * a page requires a bit more information
       */
      $params =
	array (
	  'authentication' => array(
	    'username' => $this->username,
	    'password' => $this->password,
	  ),
	  'asset' => $asset
	);
      //print_r($params);

      try {
	$this->success = false;
	$this->response = $this->client->edit($params);

	if ($this->response->editReturn->success == 'true') {
	    $this->success = true;
	}

      } catch (Exception $e) {
	//print("Edit request failed to conform to the WSDL.\n");
	//print($client->__getLastResponse() . "\n");
      }
      if ($this->success != true) {
	$this->response = $this->client->__getLastResponse() . "\n";
      }
    }


    /*
     * Delete an asset from Cascade with web services.
     */
    function delete($id) {

      if (isset($id->id)) {
	$identifier = array(
	    'type' => $id->type,
	    'id'   => $id->id,
	  );
      } else {
	$identifier = array(
	    'type' => $id->type,
	    'path' => array( 'path' => $id->path, 'siteName' => $id->siteName ),
	  );
      }
      $params = array (
	'authentication' => array(
	  'username' => $this->username,
	  'password' => $this->password,
	),
	'identifier' => $identifier,
      );
      //print_r($params);

      try {
	$this->success = false;
	$this->response = $this->client->delete($params);

	if ($this->response->deleteReturn->success == 'true') {
	    $this->success = true;
	}
      } catch (Exception $e) {
      }
      if ($this->success != true) {
	$this->response = $this->client->__getLastResponse() . "\n";
      }
    }


    /*
     * Get access rights with web services
     */
    function readAccessRights($id) {

      if (isset($id->id)) {
	$identifier = array(
	    'type' => $id->type,
	    'id'   => $id->id,
	  );
      } else if (isset($id->siteId)) {
	$identifier = array(
	    'type' => $id->type,
	    'path' => array( 'path' => $id->path, 'siteId' => $id->siteId ),
	  );
      } else {
	$identifier = array(
	    'type' => $id->type,
	    'path' => array( 'path' => $id->path, 'siteName' => $id->siteName ),
	  );
      }
      $params = array (
	  'authentication' => array(
	    'username' => $this->username,
	    'password' => $this->password,
	  ),
	  'identifier' => $identifier,
      );
      //print_r($params);

      try {
	$this->success = false;
	$this->response = $this->client->readAccessRights($params);

	if ($this->response->readAccessRightsReturn->success == 'true') {
	    $this->success = true;
	}
      } catch (Exception $e) {
      }
      if ($this->success != true) {
	$this->response = $this->client->__getLastResponse() . "\n";
      }
    }


    /*
     * Edit access rights with web services
     */
    function editAccessRights($accessRightsInformation,$applyToChildren) {

      $accessRightsInformation->allLevel = strtolower($accessRightsInformation->allLevel);
      $params = array (
	'authentication' => array(
	  'username' => $this->username,
	  'password' => $this->password,
	),
	'accessRightsInformation' => $accessRightsInformation,
	'applyToChildren' => $applyToChildren,
      );
      //print_r($params);

      try {
	$this->success = false;
	$this->response = $this->client->editAccessRights($params);

	if ($this->response->editAccessRightsReturn->success == 'true') {
	    $this->success = true;
	}
      } catch (Exception $e) {
      }
      if ($this->success != true) {
	$this->response = $this->client->__getLastResponse() . "\n";
      }
    }
}


#
# Clients should never see entityType, but Cascade includes
# it when we read assets, so we remove it here.  Otherwise,
# if we send an object we've read back to Cascade, it blows up.
#
function remove_entityType(&$obj) {
    #if (isset($obj->entityType)) {
    #    echo "Found entityType! " . $obj->entityType->name 
    #	. " in " . $obj->name . "\n";
    #}
    unset($obj->entityType);
    if (isset($obj->structuredData)) {
	$nodes = $obj->structuredData->structuredDataNodes;
	if (is_array($nodes)) {
	    foreach ($nodes as &$node) {
		remove_entityType($node);
	    }
	} else {
	    remove_entityType($nodes);
	}
    }
    if (isset($obj->pageConfigurations) &&
	isset($obj->pageConfigurations->pageConfiguration)) {
	$nodes = $obj->pageConfigurations->pageConfiguration;
	if (!is_array($nodes)) {
	    $nodes = array( $nodes );
	}
	foreach ($nodes as &$node) {
	    remove_entityType($node);
	}
    }
    if (isset($obj->pageRegions) &&
	isset($obj->pageRegions->pageRegion)) {
	$nodes = $obj->pageRegions->pageRegion;
	if (!is_array($nodes)) {
	    $nodes = array( $nodes );
	}
	foreach ($nodes as &$node) {
	    remove_entityType($node);
	}
    }
    return($obj);
}

#
# Remove empty assets and all entityTypes
#
function clean_asset(&$obj) {
    foreach ($obj as $var => $value) {
	if ($value) {
	    remove_entityType($obj->$var);
	} else {
	    unset($obj->$var);
	}
    }
}

?>
