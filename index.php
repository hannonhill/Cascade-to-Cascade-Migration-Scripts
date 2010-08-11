<?php 

  /*
  This script copies assets between sites in Cascade or from one
  instance of Cascade to another.  You can recursively copy folders
  or containers and all their contents, or copy entire sites.

  This is version 1.2 for Cascade 6.4.

  Based on the copy-folder script in Hannon Hill's CAST toolkit.

  */
//error_reporting(E_ALL ^ E_NOTICE);	# ignore undefined variables
//ini_set("display_errors",1);
ini_set("memory_limit",'256M');
include_once("cascade_soap_lib.php");

/* Configuration */
$environments = array(
      'Cascade Development' => 'dev.example.edu',
      'Cascade Test'        => 'test.example.edu',
      'Cascade Production'  => 'prod.example.edu',
);
$secure = 1;	# whether to use http or https to connect to web services

/* You shouldn't have to change anything below this line */
$verbose = 2;
$dryrun = 0;
$exit_on_error = 1;
$copytype = 'folder';
$oldPath = '/';
ob_implicit_flush(true);
ob_end_flush();

if (!empty($_POST) && validateInput()) {
    showForm();
    echo "<pre>";
    update();
    echo "</pre>";
} else {
    showForm();
}

function update() {
  global $host, $host2;
  global $uname, $uname2;
  global $pass, $pass2;
  global $oldPath, $newPath;
  global $oldSite, $newSite;
  global $readClient, $writeClient;
  global $copytype;
  global $dryrun;
  global $verbose;
  global $secure;

  $proto = 'http';
  if ($secure) $proto = 'https';

  $readClient = new CascadeSoapClient();
  if (preg_match('/^http/', $host)) {
      $readClient->url = $host . "/ws/services/AssetOperationService?wsdl";
  } else {
      $readClient->url = "$proto://" . $host . "/ws/services/AssetOperationService?wsdl";
  }
  #echo "From $readClient->url<br/>\n";
  $readClient->username = $uname;
  $readClient->password = $pass;
  $readClient->connect();

  $writeClient = new CascadeSoapClient();
  if (preg_match('/^http/', $host2)) {
      $writeClient->url = $host2 . "/ws/services/AssetOperationService?wsdl";
  } else {
      $writeClient->url = "$proto://$host2/ws/services/AssetOperationService?wsdl";
  }
  #echo "To $writeClient->url<br/>\n";
  $writeClient->username = $uname2;
  $writeClient->password = $pass2;
  $writeClient->connect();

  #
  # admin areas to include when we copy sites
  #
  $adminAreas = array(
      'assetFactory', 'contentType',
      'metaDataSet', 'pageConfigurationSet', 'publishSet',
      'structuredDataDefinition',
      #'transport',
      'workflowDefinition'
      );
  if ($oldSite == 'Global' && $newSite == 'Global') {        # old "sites"
      array_push($adminAreas, 'target');
  } else if ($oldSite != 'Global' && $newSite != 'Global') { # new "Sites"
      #array_push($adminAreas, 'connector');
      array_push($adminAreas, 'transport');
      #array_push($adminAreas, 'destination');
  }


  if ($dryrun) echo "Dry Run:\n";

    echo "Copying $copytype $host/$oldSite:$oldPath to $host2/$newSite:$newPath\n";
    if ($copytype == 'folder' || $copytype == 'site') {
      add_folder($oldPath);
    } else if (preg_match('/Container$/',$copytype)) {
      add_container($oldPath,$copytype);
    } else if (in_array($copytype,$adminAreas)) {
      checkAdminAsset($oldPath,$copytype);
    } else {
      checkAsset($oldPath,$copytype);
    }

    if ($copytype == 'site') {
      $checkPath = preg_replace("#^.*/#","/",$oldPath);
      foreach ($adminAreas as &$adminArea) {
	if ($verbose>2) echo "\nChecking ${adminArea}s ...\n";
	if ($adminArea == 'destination') {
	  $type = "siteDestinationContainer";
	} else if ($adminArea == 'target') {
	  $type = "target";
	} else {
	  $type = $adminArea . "Container";
	}
	add_container($checkPath,$type);
      }
    }

  setPermissions();
  if ($dryrun) {
    echo "Dry Run Complete\n";
  } else {
    echo "Done.\n";
  }
}



/*
* Read the folder, push its children, make the folder, then pop remaining children 
*/
function add_folder($path) {
    global $oldPath;
    global $oldSite, $newSite;
    global $readClient, $writeClient;
    global $folderStack;
    global $pageStack;
    global $refStack;
    global $genericStack;
    global $templateStack;
    global $skipPattern;
    global $checked;
    global $exit_on_error;

    $type = 'folder';
    if (isset($checked["$type.$path"])) {
      return;
    }
    if (isset($skipPattern) && preg_match("#$skipPattern#i",$path)) {
       echo "*** Skipping $type $path\n";
       return;
    }

    #
    # create the folder itself
    #
    checkFolder($path);

    #
    # and all its children
    #
    $id->path = $path;
    $id->siteName = $oldSite;
    $id->type = "folder";
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $path . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    if (isset($asset->$type->children)
	&& isset($asset->$type->children->child)) {
	$children = $asset->$type->children->child;
	if (!is_array($children)) {
	    $children = array( $children );
	}
    } else {
	$children = "";
    }
    if (is_array($children)) {
      while ($cur = array_pop($children)) {
	if ($cur->type == "folder") {
	  $folderStack[] = $cur;
	} else if ($cur->type == "page") {
	  $pageStack[] = $cur;
	} else if ($cur->type == "reference") {
	  $refStack[] = $cur;
	} else if ($cur->type == "template") {
	  $templateStack[] = $cur;
	} else {
	  $genericStack[] = $cur;
	}
      }
    }
    if (is_array($folderStack)) {
      while ($cur = array_pop($folderStack)) {
	add_folder($cur->path->path);
      }
    }
    if (is_array($genericStack)) {
      while ($cur = array_pop($genericStack)) {
	checkAsset($cur->path->path, $cur->type);
      }
    }
    if (is_array($templateStack)) {
      while ($cur = array_pop($templateStack)) {
	checkAsset($cur->path->path, $cur->type);
      }
    }
    if (is_array($pageStack)) {
      while ($cur = array_pop($pageStack)) {
	checkAsset($cur->path->path, $cur->type);
      }
    }
    if (is_array($refStack)) {
      while ($cur = array_pop($refStack)) {
	checkAsset($cur->path->path, $cur->type);
      }
    }
}

/*
 * Read in the file, block, symlink, or ... and add it directly 
 */
function checkAsset($path, $type) {
    global $oldPath, $newPath;
    global $oldSite, $newSite;
    global $readClient, $writeClient;
    global $skipPattern;
    global $dryrun;
    global $verbose;
    global $checked;
    global $exit_on_error;

    #
    # see if we've already checked it
    #
    if (isset($checked["$type.$path"])) {
      return;
    } else {
      $checked["$type.$path"] = 1;
    }
    if ($dryrun && $type == 'file' && $verbose<2) {
	return;	# speed things up a bit
    }
    if (isset($skipPattern) && preg_match("#$skipPattern#i",$path)) {
       echo "*** Skipping $type $path\n";
       return;
    }

  #
  # reference to another site
  #
  if (preg_match('/^(.*):(.*)/',$path,$matches)) {
    global $host, $host2;
    if ($host == $host2) {
      if ($verbose>2) echo "Ok: $type $path\n";
      return;
    } else {
      preg_match('/^(.*):(.*)/',$path,$matches);
      $site = $matches[1];
      $path = $matches[2];
      if ($verbose>2) echo "Checking $type $site:$path\n";
      $id->path = $path;
      $id->siteName = $site;
      $id->type = strtolower($type);
      $writeClient->read($id);
      if (!$writeClient->success) {
	  if (preg_match('/Unable to identify an entity/',$writeClient->response)) {
	      echo "
*** Found cross-site reference to $site $type $path, which does not exist in the
target environment.  You must change this before the copy can proceed.
";
	  } else {
	      echo "Failed reading: " . $site . ' ' . $path . "\n";
	      echo print_r($id);
	      echo cleanup($writeClient->response);
	  }
	  if ($exit_on_error) cleanexit(); else return;
      }
      return;
    }
  }

    #
    # see if asset already exists in new location
    #
if ($verbose>2) echo "Checking $type: " . getPath($path) . "\n";
    $id->path = getPath($path);
    $id->siteName = $newSite;
    $id->type = $type;
    $writeClient->read($id);
    if ($writeClient->success) {
	return;
    } else if (preg_match('/Unable to identify/',$writeClient->response)) {
      #echo "Can't find " . $id->path . " in " . $id->siteName . "\n";
    } else {
      echo "Read failure on destination: " . getPath($path) . cleanup($writeClient->response);
      print_r($id);
      if ($exit_on_error) cleanexit(); else return;
    }

    #
    # read source asset and copy to destination
    #
    $id->path = $path;
    $id->siteName = $oldSite;
    $id->type = $type;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Read failure on $path: " . cleanup($readClient->response);
      print_r($id);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    if (!isset($asset->$type)) {
      if (isset($asset->indexBlock)) {
	$type = "indexBlock";
      } else if (isset($asset->textBlock)) {
	$type = "textBlock";
      } else if (isset($asset->feedBlock)) {
	$type = "feedBlock";
      } else if (isset($asset->xmlBlock)) {
	$type = "xmlBlock";
      } else if (isset($asset->xhtmlBlock)) {
	$type = "xhtmlBlock";
      } else if (isset($asset->xsltFormat)) {
	$type = "xsltFormat";
      } else if (isset($asset->scriptFormat)) {
	$type = "scriptFormat";
      }
    }
    if ($type == 'xsltFormat') {
	$asset->$type->xml = preg_replace(
	    '#1.0"xmlns#', '1.0" xmlns', $asset->$type->xml);
    }
    if ($type == 'file' || preg_match('/\.css$/',$asset->$type->name)) {
	$asset->$type->data =
	    preg_replace('#\[system-asset(?::id=\w+)?\]([^\[]*)\[/system-asset\]#',
		'/renderfile/global$1', $asset->$type->data);
	$asset->$type->text =
	    preg_replace('#\[system-asset(?::id=\w+)?\]([^\[]*)\[/system-asset\]#',
		'/renderfile/global$1', $asset->$type->text);
    }
    if ($type == 'page' && $oldPath != $newPath) {
	$asset->$type->xhtml = preg_replace(
	    "#$oldPath#", $newPath, $asset->$type->xhtml);
    }
    # remove [system-asset] tags from symlinks
    if ($type == 'symlink' && isset($asset->$type->linkURL)) {
	$asset->$type->linkURL = 
	    preg_replace('#\[system-asset(?::id=\w+)?\]([^\[]*)\[/system-asset\]#',
	    '$1', $asset->$type->linkURL);
    }
    if (isset($asset->$type->parentFolderPath)) {
      checkFolder($asset->$type->parentFolderPath);
      $asset->$type->parentFolderPath = getPath($asset->$type->parentFolderPath);
    }
    if (isset($asset->$type->metadataSetPath)) {
      checkAdminAsset($asset->$type->metadataSetPath,'metaDataSet');
    }
    if (isset($asset->$type->structuredData) &&
	is_string($asset->$type->structuredData->definitionPath)) {
      checkAdminAsset($asset->$type->structuredData->definitionPath,'structuredDataDefinition');
    }
    if (isset($asset->$type->configurationSetPath)) {
      checkAdminAsset($asset->$type->configurationSetPath,'pageConfigurationSet');
    } else {
      unset($asset->$type->configurationSetPath);
    }
    if (isset($asset->$type->contentTypePath)) {
      checkAdminAsset($asset->$type->contentTypePath,"contentType");
    }
    if (isset($asset->$type->indexedContentTypePath)) {
      checkAdminAsset($asset->$type->indexedContentTypePath,"contentType");
    }
    if (isset($asset->$type->indexedFolderPath)) {
      if (want($asset->$type->indexedFolderPath,'folder')) {
	checkAsset($asset->$type->indexedFolderPath,'folder');
	$asset->$type->indexedFolderPath = getPath($asset->$type->indexedFolderPath);
      } else {
	if ($verbose>1) echo "*** Adjusting indexedFolderPath in $type $path\n";
	$asset->$type->indexedFolderPath = null;
      }
    } else {
	# silly php, if it's not set, why do we need to unset it?
	if ($type == 'indexBlock') {
	    #echo "*** Removing indexedFolderPath in $type $path\n";
	    unset($asset->$type->indexedFolderPath);
	}
    }
    if (isset($asset->$type->expirationFolderPath)) {
      if (want($asset->$type->expirationFolderPath,'folder')) {
	checkAsset($asset->$type->expirationFolderPath,'folder');
	$asset->$type->expirationFolderPath = getPath($asset->$type->expirationFolderPath);
      } else {
	if ($verbose>1) echo "Adjusting expirationFolderPath in $type $path\n";
	$asset->$type->expirationFolderPath = null;
      }
    }
    if (isset($asset->$type->formatPath)) {
      checkAsset($asset->$type->formatPath,'format');
      $asset->$type->formatPath = getPath($asset->$type->formatPath);
    }
    if (isset($asset->$type->targetPath)) {
      checkTarget($asset->$type->targetPath);
    }
    if (isset($asset->$type->structuredData) &&
        is_string($asset->$type->structuredData->definitionPath)) {
      checkAdminAsset($asset->$type->structuredData->definitionPath,'structuredDataDefinition');
    }
    if (isset($asset->$type->structuredData) &&
        isset($asset->$type->structuredData->structuredDataNodes)) {
      adjustStructuredData($path,$asset->$type->structuredData->structuredDataNodes);
      unset($asset->$type->structuredData->definitionId);
    } else {
	unset($asset->$type->structuredData);
    }
    if (isset($asset->$type->pageConfigurations)) {
      adjustPageConfigurations($path,$asset->$type->pageConfigurations);
    }
    if (isset($asset->$type->pageRegions)) {
      adjustPageRegions($path,$asset->$type->pageRegions);
    }
    if (isset($asset->$type->referencedAssetId)) {
      unset($asset->$type->referencedAssetId);
      if ($asset->$type->referencedAssetType == 'folder') {
	  checkFolder($asset->$type->referencedAssetPath);
      } else {
	  checkAsset($asset->$type->referencedAssetPath,
	      $asset->$type->referencedAssetType);
      }
      $asset->$type->referencedAssetPath =
	getPath($asset->$type->referencedAssetPath);
    }
    unset($asset->$type->id);
    unset($asset->$type->configurationSetId);
    unset($asset->$type->contentTypeId);
    unset($asset->$type->formatId);
    unset($asset->$type->expirationFolderId);
    unset($asset->$type->indexedContentTypeId);
    unset($asset->$type->indexedFolderId);
    unset($asset->$type->metadataSetId);
    unset($asset->$type->path);
    unset($asset->$type->parentFolderId);
    unset($asset->$type->targetId);
    setSite($asset,$type);

    if (isset($asset->$type->data)) {
      unset($asset->$type->text);
      //$asset->$type->data = base64_decode($asset->$type->data);
    }

    if ($verbose>1) echo "Creating: $type " . getPath($path) . "\n";
    if ($verbose==1) echo ".";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	echo "Failed: $type " . getPath($path) . "\n";
	print_r($asset);
	echo cleanup($writeClient->response);
        if ($exit_on_error) cleanexit(); else return;
      }
    }
    remember($path,$id);
}

function checkTarget($path) {
  global $readClient, $writeClient;
  global $oldSite, $newSite;
  global $dryrun;
  global $verbose;
  global $checked;
  global $exit_on_error;

  $type = "target";
  if (isset($checked["$type.$path"])) {
    return;
  } else {
    $checked["$type.$path"] = 1;
  }
  if ($path == '/') {
    return;
  }
if ($verbose>2) echo "Checking $type $path ...\n";
  $id->path = $path;
  $id->siteName = $newSite;
  $id->type = $type;
  $writeClient->read($id);
  if (!$writeClient->success) {
    $id->siteName = $oldSite;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $path . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    unset($asset->$type->id);
    unset($asset->$type->path);
    unset($asset->$type->parentTargetId);
    unset($asset->$type->baseFolderId);
    if ($asset->$type->parentTargetPath != "") {
      checkTarget($asset->$type->parentTargetPath);
    }
    $asset->$type->baseFolderPath = getPath($asset->$type->baseFolderPath);
    if ($asset->$type->cssFilePath != "") {
      checkAsset($asset->$type->cssFilePath,'file');
      $asset->$type->cssFilePath = getPath($asset->$type->cssFilePath);
    }
    if ($asset->$type->publishIntervalUnits == "Hours") {
      $asset->$type->publishIntervalUnits = "hours";
    }
    setSite($asset,$type);
    unset($asset->$type->children);
    unset($asset->$type->cssFileId);

    if ($verbose>1) echo "Creating: $type " . $path . "\n";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	echo "\nFailed: $type " . $path . "\n";
	print_r($asset);
	echo cleanup($writeClient->response);
        if ($exit_on_error) cleanexit(); else return;
      }
    }
    remember($path,$id);
  }
}

function checkFolder($path) {
  global $readClient, $writeClient;
  global $oldPath, $newPath;
  global $oldSite, $newSite;
  global $skipPattern;
  global $dryrun;
  global $verbose;
  global $checked;
  global $exit_on_error;

  $type = 'folder';
  if (isset($checked["$type.$path"])) {
    return;
  } else {
    $checked["$type.$path"] = 1;
  }
  if ($path == '/') {
    return;
  }
  if (isset($skipPattern) && preg_match("#$skipPattern#i",$path)) {
     echo "*** Skipping $type $path\n";
     return;
  }
if ($verbose>2) echo "Checking folder " . getPath($path) . " ...\n";
  $id->path = getPath($path);
  $id->siteName = $newSite;
  $id->type = strtolower($type);
  $writeClient->read($id);
  if (!$writeClient->success) {
    $id->path = $path;
    $id->siteName = $oldSite;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $path . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    if ($asset->$type->parentFolderPath == "") {
      $asset->$type->parentFolderPath = "/";
    } else {
      checkFolder($asset->$type->parentFolderPath);
    }
    #
    # if this is the main folder we're copying, we may need to rename it
    #
    if ($path == $oldPath) {
	$oldName = preg_replace(",^.*/,", "", $oldPath);
	$newName = preg_replace(",^.*/,", "", $newPath);
	if ($oldName != $newName) {
	    $asset->$type->name = $newName;
	}
    }
    $asset->$type->parentFolderPath = getPath($asset->$type->parentFolderPath);

    checkAdminAsset($asset->$type->metadataSetPath,'metaDataSet');
    unset($asset->$type->id);
    unset($asset->$type->children);
    unset($asset->$type->path);
    unset($asset->$type->parentFolderId);
    unset($asset->$type->metadataSetId);
    unset($asset->$type->expirationFolderId);
    setSite($asset,$type);

    if ($verbose) echo "Creating: $type " . getPath($path) . "\n";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	echo "\nFailed: $type " . getPath($path) . "\n";
	print_r($asset);
	echo cleanup($writeClient->response);
        if ($exit_on_error) cleanexit(); else return;
      }
    }
    remember($path,$id);
  }
}

function checkAdminAsset($path,$type) {
  global $readClient, $writeClient;
  global $oldSite, $newSite;
  global $dryrun;
  global $verbose;
  global $checked;
  global $exit_on_error;

  if (isset($checked["$type.$path"])) {
    return;
  } else {
    $checked["$type.$path"] = 1;
  }

  #
  # reference to another site
  #
  if (preg_match('/^(.*):(.*)/',$path,$matches)) {
    global $host, $host2;
    if ($host == $host2) {
      if ($verbose>2) echo "Ok: $type $path\n";
      return;
    } else {
      preg_match('/^(.*):(.*)/',$path,$matches);
      $site = $matches[1];
      $path = $matches[2];
      if ($verbose>2) echo "Checking $type $site:$path\n";
      $id->path = $path;
      $id->siteName = $site;
      $id->type = strtolower($type);
      $writeClient->read($id);
      if (!$writeClient->success) {
	  if (preg_match('/Unable to identify an entity/',$writeClient->response)) {
	      echo "
*** Found cross-site reference to $site $type $path, which does not exist 
in target environment.  You must change this before the copy can proceed.
";
	  } else {
	      echo "Failed reading: " . $site . ' ' . $path . "\n";
	      echo print_r($id);
	      echo cleanup($writeClient->response);
	  }
	  if ($exit_on_error) cleanexit(); else return;
      }
      return;
    }
  }

if ($verbose>2) echo "Checking $type $path\n";
  $id->path = $path;
  $id->siteName = $newSite;
  $id->type = strtolower($type);
  $writeClient->read($id);
  if (!$writeClient->success) {
    $id->siteName = $oldSite;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $path . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    $container = $type.'Container';
    if (!isset($asset->$type)) {
      if (isset($asset->fileSystemTransport)) {
	$type = "fileSystemTransport";
	$container = "transportContainer";
      } else if (isset($asset->ftpTransport)) {
	$type = "ftpTransport";
	$container = "transportContainer";
	echo "*** Can't set password on $type: $path\n";
	$asset->$type->password = 'UNKNOWN';
      } else if (isset($asset->databaseTransport)) {
	$type = "databaseTransport";
	$container = "transportContainer";
      } else if (isset($asset->metadataSet)) {
	$type = "metadataSet";
	$container = "metadataSetContainer";
      }
    }

    unset($asset->$type->id);
    unset($asset->$type->path);
    unset($asset->$type->parentContainerId);
    if (isset($asset->$type->parentContainerPath)) {
	if ($type == 'destination') {
	    checkTarget($asset->$type->parentContainerPath);
	} else {
	    checkContainer($asset->$type->parentContainerPath,$container);
	}
    }
    setSite($asset,$type);

    if ($type == 'assetFactory') {
	unset($asset->$type->baseAssetId);
	unset($asset->$type->placementFolderId);
	unset($asset->$type->workflowDefinitionId);
	if ($asset->$type->assetType == 'format') {
	  echo "*** Skipping asset factory of type 'format': " . $path . "\n";
	  return;
	}
	if (isset($asset->$type->assetType) && isset($asset->$type->baseAssetPath)) {
	    if ($asset->$type->assetType == 'folder') {
		checkFolder($asset->$type->baseAssetPath);
	    } else {
		checkAsset($asset->$type->baseAssetPath,$asset->$type->assetType);
	    }
	}
	if (isset($asset->$type->placementFolderPath)) {
	    checkFolder($asset->$type->placementFolderPath);
	}
	# as per CSCD-4324 asset factory's with multiple applicable groups do not persist
	if (isset($asset->$type->applicableGroups)) {
	    $asset->$type->applicableGroups =
		preg_replace("/,/", ";", $asset->$type->applicableGroups);
	}
	if (isset($asset->$type->workflowDefinitionPath)) {
	    checkAdminAsset($asset->$type->workflowDefinitionPath,'workflowDefinition');
	}

    } else if ($type == 'destination') {
	unset($asset->$type->transportId);
	# it's too hard to create destinations, so ...
	echo "*** Skipping destination $path\n";
	return;

    } else if ($type == 'pageConfigurationSet') {
      if (isset($asset->$type->pageConfigurations)) {
	adjustPageConfigurations($path,$asset->$type->pageConfigurations);
      }

    } else if ($type == 'publishSet') {
	foreach (array('pages','files','folders') as $thing) {
	    $area = $asset->$type->$thing;
	    if (isset($area->publishableAssetIdentifier) &&
		is_array($area->publishableAssetIdentifier)) {
		#echo "*** Removing Ids from $thing in $path\n";
		removeIds($area->publishableAssetIdentifier);
	    }
	}

    } else if ($type == 'contentType') {
      unset($asset->$type->metadataSetId);
      unset($asset->$type->pageConfigurationSetId);
      unset($asset->$type->structuredDataDefinitionId);
      if (isset($asset->$type->metadataSetPath)) {
	  checkAdminAsset($asset->$type->metadataSetPath,'metaDataSet');
      }
      if (isset($asset->$type->pageConfigurationSetPath)) {
	  checkAdminAsset($asset->$type->pageConfigurationSetPath,'pageConfigurationSet');
      }
      if (isset($asset->$type->structuredDataDefinitionPath)) {
	  checkAdminAsset($asset->$type->structuredDataDefinitionPath,'structuredDataDefinition');
      }
    }
    //print_r($asset);


    if ($verbose>1) echo "Creating: $type " . $path . "\n";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	echo "\nFailed: $type " . $path . "\n";
	print_r($asset);
	echo cleanup($writeClient->response);
        if ($exit_on_error) cleanexit(); else return;
      }
    }
    remember($path,$id);
  }
}


function checkContainer($path,$type) {
  global $readClient, $writeClient;
  global $oldSite, $newSite;
  global $dryrun;
  global $verbose;
  global $checked;
  global $exit_on_error;

  if (isset($checked["$type.$path"])) {
    return;
  } else {
    $checked["$type.$path"] = 1;
  }
  if (!isset($path)) {
    return;
  }
if ($verbose>2) echo "Checking $type $path ...\n";
  $id->path = $path;
  $id->siteName = $newSite;
  $id->type = strtolower($type);
  $writeClient->read($id);
  if (!$writeClient->success) {
    $id->siteName = $oldSite;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $path . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    unset($asset->$type->id);
    unset($asset->$type->path);
    unset($asset->$type->parentContainerId);
    unset($asset->$type->children);
    if (isset($asset->$type->parentContainerPath)) {
      checkContainer($asset->$type->parentContainerPath,$type);
    }
    setSite($asset,$type);

    if ($verbose) echo "Creating: $type " . $path . "\n";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	echo "\nFailed: $type " . $path . "\n";
	print_r($asset);
	echo cleanup($writeClient->response);
        if ($exit_on_error) cleanexit(); else return;
      }
    }
    remember($path,$id);
  }
}

/*
 * Copy a container and its contents
 */
function add_container($path,$type) {
    global $oldPath;
    global $oldSite, $newSite;
    global $readClient, $writeClient;
    global $checked;
    global $exit_on_error;

    if (isset($checked["$type.$path"])) {
      return;
    }

    #
    # create the container
    #
    if ($type == 'target') {
	checkTarget($path);
    } else {
	checkContainer($path,$type);
    }

    #
    # and all its children
    #
    $id->path = $path;
    $id->siteName = $oldSite;
    $id->type = strtolower($type);
    $readClient->read($id);
    if (!$readClient->success) {
	echo "Failed reading: " . $path . "\n";
	echo cleanup($readClient->response);
        if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    if (isset($asset->$type->children)
	&& isset($asset->$type->children->child)) {
	$children = $asset->$type->children->child;
	if (!is_array($children)) {
	    $children = array( $children );
	}
    } else {
	$children = "";
    }
    $types = array(
	"assetFactoryContainer",
	"pageConfigurationSetContainer",
	"contentTypeContainer",
	"structuredDataDefinitionContainer",
	"metadataSetContainer",
	"publishSetContainer",
	"siteDestinationContainer",
	"transportContainer",
	"workflowDefinitionContainer",
	"assetFactory",
	"pageConfigurationSet",
	"contentType",
	"structuredDataDefinition",
	"metaDataSet",
	"publishSet",
	"destination",
	"target",
	"transport",
	"workflowDefinition",
    );
    foreach ($types as $type) {
	$names[strtolower($type)] = $type;
    }
    if (is_array($children)) {
	while ($cur = array_pop($children)) {
	    if (array_key_exists($cur->type,$names)) {
		if (preg_match('/container/',$cur->type)) {
		    add_container($cur->path->path, $names[$cur->type]);
		} else if ($cur->type == 'target') {
		    checkTarget($cur->path->path);
		    add_container($cur->path->path, $names[$cur->type]);
		} else {
		    checkAdminAsset($cur->path->path, $names[$cur->type]);
		}
	    } else {
		echo "Oops: don't know what to do with "
		  . $cur->type . " " . $cur->path->path . "\n";
	    }
	}
    }
}


#
# We want assets if they are in the original site and path,
# or if they already exist in the new site and path.  
# When we find references to assets we don't want, we
# try to change them, e.g. when creating a group, if we don't
# want the groupBaseFolder, then unset this field.
#
function want($path,$type) {
    global $readClient, $writeClient;
    global $oldPath;
    global $newSite;
    global $want;

    if (isset($want["$type.$path"])) {
	return($want["$type.$path"]);
    }

    $homearea = array(
	'block', 'file', 'folder', 'page', 'reference', 'xsltFormat',
	'scriptFormat', 'symlink', 'template' );

    if (in_array($type,$homearea)) {
	$checkPath = $oldPath;
    } else if ($type == 'transport') {
	$checkPath = 'FTP and SFTP/' . $oldPath;
    } else if ($type == 'group') {
	$checkPath = preg_replace(",/,", "|", $oldPath);  # match any component
    } else {
	$checkPath = preg_replace("#^www/#","",$oldPath);
    }
    if (preg_match("#^$checkPath#", $path)
	|| preg_match("#^$path(/|$)#",$checkPath)) {
	# we want it for sure
	$want["$type.$path"] = true;
	return(true);
    } else {
	# we only want it if it already exists
	if ($type == 'group' || $type == 'user') {
	    $id->id = getName($path);
	} else {
	    $id->path = getPath($path);
	}
	$id->type = strtolower($type);
	$id->siteName = $newSite;
	$writeClient->read($id);
	if ($writeClient->success) {
	    $want["$type.$path"] = true;
	    return(true);
	}
    }
    $want["$type.$path"] = false;
    return(false);
}


function checkGroup($group) {
  global $readClient, $writeClient;
  global $oldPath;
  global $newSite;
  global $dryrun;
  global $verbose;
  global $checked;
  global $exit_on_error;

  $type = "group";
  if (isset($checked["$type.$group"])) {
    return;
  } else {
    $checked["$type.$group"] = 1;
  }
if ($verbose>2) echo "Checking $type " . getName($group) . "...\n";
  $id->id = getName($group);
  $id->type = strtolower($type);
  $writeClient->read($id);
  if (!$writeClient->success) {
    $id->id = $group;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $group . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    $asset->$type->groupName = getName($group);

    #
    # only keep members with accounts in new environment
    #
    global $host, $host2;
    if ($host != $host2 && isset($asset->$type->users)) {
      $users = preg_split('/;/',$asset->$type->users);
      $newusers = array();
      foreach ($users as &$user) {
	$id->id = $user;
	$id->type = 'user';
	$writeClient->read($id);
	if ($writeClient->success) {
	  array_push($newusers,$user);
	}
      }
      $asset->$type->users = join(';',$newusers);
    }

    unset($asset->$type->groupAssetFactoryContainerId);
    unset($asset->$type->groupBaseFolderId);
    unset($asset->$type->groupStartingPageId);
    if (want($asset->$type->groupAssetFactoryContainerPath,'assetFactoryContainer')) {
	checkContainer($asset->$type->groupAssetFactoryContainerPath,'assetFactoryContainer');
    } else {
	if ($verbose>1) echo "*** Adjusting groupAssetFactoryContainerPath in $type $group\n";
	$asset->$type->groupAssetFactoryContainerPath = null;
    }
    if (isset($asset->$type->groupStartingPagePath)) {
	if (want($asset->$type->groupStartingPagePath,'page')) {
	    checkAsset($asset->$type->groupStartingPagePath,'page');
	    $asset->$type->groupStartingPagePath =
	      getPath($asset->$type->groupStartingPagePath);
	} else {
	  if ($verbose>1) echo "*** Adjusting groupStartingPagePath in $type $group\n";
	  $asset->$type->groupStartingPagePath = null;
	}
    }
    if (isset($asset->$type->groupBaseFolderPath)) {
	if (want($asset->$type->groupBaseFolderPath,'folder')) {
	    checkFolder($asset->$type->groupBaseFolderPath);
	    $asset->$type->groupBaseFolderPath =
	      getPath($asset->$type->groupBaseFolderPath);
	} else {
	    if ($verbose>1) echo "*** Adjusting groupBaseFolderPath in $type $group\n";
	    $asset->$type->groupBaseFolderPath = null;
	}
    }

    if ($verbose) echo "Creating: $type " . getName($group) . "\n";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	if (!preg_match('/already exists/', $writeClient->message)) {
	  echo "\nFailed: $type " . getName($group) . "\n";
	  print_r($asset);
	  echo cleanup($writeClient->response);
	  if ($exit_on_error) cleanexit(); else return;
	}
      }
    }
  }
}


function checkUser($user) {
  global $readClient, $writeClient;
  global $newSite;
  global $dryrun;
  global $verbose;
  global $checked;
  global $exit_on_error;

  $type = "user";
  if (isset($checked["$type.$user"])) {
    return;
  } else {
    $checked["$type.$user"] = 1;
  }
if ($verbose>2) echo "Checking $type " . getName($user) . "...\n";
  $id->id = getName($user);
  $id->type = strtolower($type);
  $writeClient->read($id);
  if (!$writeClient->success) {
    $id->id = $user;
    $readClient->read($id);
    if (!$readClient->success) {
      echo "Failed reading: " . $user . "\n";
      echo cleanup($readClient->response);
      if ($exit_on_error) cleanexit(); else return;
    }
    $asset = $readClient->asset;
    if (isset($asset->$type->defaultGroup)) {
	checkGroup($asset->$type->defaultGroup);
	$asset->$type->defaultGroup =
	  getName($asset->$type->defaultGroup);
    }
    if (isset($asset->$type->groups)) {
      $groups = preg_split('/;/',$asset->$type->groups);
      $newgroups = array();
      foreach ($groups as &$group) {
	if (getName($group) == $asset->$type->defaultGroup) {
	  array_push($newgroups,getName($group));
	} else if (want($group,'group')) {
	  checkGroup($group);
	  array_push($newgroups,getName($group));
	}
      }
      $asset->$type->groups = join(';',$newgroups);
    }
    echo "*** Can't set password on $type: $user\n";

    if ($verbose) echo "Creating: $type " . getName($user) . "\n";
    if (!$dryrun) {
      $writeClient->create($asset);
      if (!$writeClient->success) {
	echo "\nFailed: $type " . getName($user) . "\n";
	print_r($asset);
	echo cleanup($writeClient->response);
        if ($exit_on_error) cleanexit(); else return;
      }
    }
  }
}


/*
* Gets the new folder path, by converting it from the old to the new
*/
function getPath($folderPath) {
  global $oldPath;
  global $newPath;
  if (!isset($folderPath)) {
    return($folderPath);
  }
  if ($folderPath[0] == "/") {
    $folderPath = substr($folderPath, 1);
  }
  //if it's in the current path
  if (strpos($folderPath, $oldPath) === 0) {
    /* tack newPath in front, then strip the trailing / if there is one */
    $folderPath = $newPath . "/" . substr($folderPath, strlen($oldPath));
    $folderPath = str_replace("//", "/", $folderPath);
    if (substr($folderPath, strlen($folderPath) - 1) === "/") {
      $folderPath = substr($folderPath, 0, strlen($folderPath) - 1);
    }
  }
  if ($folderPath == "") $folderPath = "/";
  return $folderPath;
}

/*
* Get the new group or username, by converting it from the old to the new
*/
function getName($name) {
    global $oldPath;
    global $newPath;
    $oldName = preg_replace(",^.*/,", "", $oldPath);
    $newName = preg_replace(",^.*/,", "", $newPath);
    if (preg_match("/^$oldName/", $name)) {
	$name = preg_replace("/^$oldName/", $newName, $name);
    }
    return $name;
}

function removeIds($array) {
    foreach ($array as &$item) {
	unset($item->id);
    }
}

function adjustPageConfigurations($path,$pageConfigurations) {
    if (isset($pageConfigurations) &&
	isset($pageConfigurations->pageConfiguration)) {
      $configs = $pageConfigurations->pageConfiguration;
      if (!is_array($configs)) {
	$configs = array( $configs );
      }
      foreach ($configs as &$config) {
	unset($config->id);
	unset($config->templateId);
	unset($config->formatId);
	if (isset($config->templatePath)) {
	    checkAsset($config->templatePath,'template');
	    $config->templatePath = getPath($config->templatePath);
	}
	if (isset($config->pageRegions)) {
	    adjustPageRegions($path,$config->pageRegions);
	}
      }
    }
}

function adjustPageRegions($path,$pageRegions) {
    if (!isset($pageRegions)) {
      return;
    }
    if (isset($pageRegions) &&
	isset($pageRegions->pageRegion)) {
      $regions = $pageRegions->pageRegion;
      if (!is_array($regions)) {
	$regions = array( $regions );
      }
      foreach ($regions as &$region) {
	unset($region->id);
	unset($region->blockId);
	unset($region->formatId);
	if (!$region->blockPath) unset($region->blockPath);
	if (!$region->noBlock) unset($region->noBlock);
	if (!$region->formatPath) unset($region->formatPath);
	if (!$region->noFormat) unset($region->noFormat);
	if (isset($region->blockPath)) {
	  checkAsset($region->blockPath,"block");
	  $region->blockPath = getPath($region->blockPath);
	}
	if (isset($region->formatPath)) {
	  checkAsset($region->formatPath,"format");
	  $region->formatPath = getPath($region->formatPath);
	}
      }
    }
}

function adjustStructuredData($path,$structuredDataNodes) {
    global $checked;
    global $verbose;

    if (isset($structuredDataNodes) &&
	isset($structuredDataNodes->structuredDataNode)) {
      $nodes = $structuredDataNodes->structuredDataNode;
      if (!is_array($nodes)) {
	  $nodes = array( $nodes );
      }
      foreach ($nodes as &$node) {
	unset($node->blockId);
	unset($node->fileId);
	unset($node->pageId);
	unset($node->symlinkId);
	if ($node->type != 'asset') {
	    unset($node->assetType);
	} else {
	    if ($node->assetType != 'block') unset($node->blockPath);
	    if ($node->assetType != 'file') unset($node->filePath);
	    if ($node->assetType != 'page') unset($node->pagePath);
	    if ($node->assetType != 'symlink') unset($node->symlinkPath);
	    if ($node->assetType != 'text') unset($node->text);
	}
	if (isset($node->blockPath)) {
	    checkAsset($node->blockPath,'block');
	    $node->blockPath = getPath($node->blockPath);
	}
	if (isset($node->filePath)) {
	    checkAsset($node->filePath,'file');
	    $node->filePath = getPath($node->filePath);
	}
	if (isset($node->pagePath)) {
	    checkAsset($node->pagePath,'page');
	    $node->pagePath = getPath($node->pagePath);
	}
	if (isset($node->symlinkPath)) {
	    checkAsset($node->symlinkPath,'symlink');
	    $node->symlinkPath = getPath($node->symlinkPath);
	}
	if ($node->type == 'group') {
	    adjustStructuredData($path,$node->structuredDataNodes);
	} else {
	    unset($node->structuredDataNodes);
	}
	#
	# web services won't let us create a file field without a file ...
	#
	if ($node->type == 'asset') {
	  if ($node->assetType == 'file' && !isset($node->filePath)) {
	    $node->filePath = null;
	  } else if ($node->assetType == 'page' && !isset($node->pagePath)) {
	    $node->pagePath = null;
	  } else if ($node->assetType == 'block' && !isset($node->blockPath)) {
	    $node->blockPath = null;
	  }
	}
	if ($node->type == 'text' && !isset($node->text)) {
	    $node->text = null;
	}
      }
    }
}

#
# remember things we've created so we can set permissions later
#
function remember($path,$id) {
    global $created;
    $created[] = $path;
    $created[] = $id;
}

function setPermissions() {
    global $created;
    global $verbose;
    if (is_array($created)) {
	if ($verbose == 1)
	    echo "Setting access rights ...\n";
	while ($path = array_shift($created)) {
	    $id = array_shift($created);
	    setAccessRights($path,$id);
	}
    }
}

function setAccessRights($path,$id) {
    global $readClient, $writeClient;
    global $dryrun;
    global $verbose;

    $readClient->readAccessRights($id);
    if ($readClient->success) {
        $accessRightsInformation = $readClient->response->readAccessRightsReturn->accessRightsInformation;
        $accessRightsInformation = adjustAccessRights($accessRightsInformation);
	if ($verbose > 1)
	    echo "Setting access rights on " . $id->type . " " . getPath($path) . "\n";
	if (!$dryrun) {
	  $writeClient->editAccessRights($accessRightsInformation, false);
	  if (!$writeClient->success) {
	    echo "\nFailed set access rights: " . $id->type . " " . getPath($path) . "\n";
	    echo cleanup($writeClient->response);
	    if ($exit_on_error) cleanexit(); else return;
	  }
	}
    }
}

function adjustAccessRights($accessRightsInformation) {
    global $newSite;

    $accessRightsInformation->identifier->path->path =
	getPath($accessRightsInformation->identifier->path->path);
    if ($newSite == 'Global') {
	$accessRightsInformation->identifier->path->siteName = "";
    } else {
	$accessRightsInformation->identifier->path->siteName = $newSite;
    }
    unset($accessRightsInformation->identifier->path->siteId);
    unset($accessRightsInformation->identifier->id);

    if (isset($accessRightsInformation->aclEntries) &&
	isset($accessRightsInformation->aclEntries->aclEntry)) {
	$nodes = $accessRightsInformation->aclEntries->aclEntry;
	if (!is_array($nodes)) {
	    $nodes = array( $nodes );
	}
	$newnodes = array();
	foreach ($nodes as &$node) {
	    if ($node->type == 'group') {
		if (want($node->name,'group')) {
		    checkGroup($node->name);
		    $node->name = getName($node->name);
		    array_push($newnodes,$node);
		}
	    } else if ($node->type == 'user') {
		checkUser($node->name);
		array_push($newnodes,$node);
	    }
	}
	$accessRightsInformation->aclEntries->aclEntry = $newnodes;
    }
    return $accessRightsInformation;
}

function setSite($asset,$type) {
    global $oldSite, $newSite;

    if ($newSite == 'Global') {
	  unset($asset->$type->siteName);
    } else {
	  $asset->$type->siteName = $newSite;
    }
    unset($asset->$type->siteId);
}


function validateInput() {
    global $host, $host2;
    global $uname, $uname2;
    global $pass, $pass2;
    global $oldPath, $newPath;
    global $oldSite, $newSite;
    global $skipPattern;
    global $copytype, $dryrun, $verbose;
    global $exit_on_error;

    if ( !empty($_POST['host1'])
      && !empty($_POST['site1'])
      && !empty($_POST['username1'])
      && !empty($_POST['password1'])
      && !empty($_POST['folder1'])
      && !empty($_POST['copytype'])
      ) {

	$host = $_POST['host1'];
	if (empty($_POST['host2'])) {
	    $host2 = $host;
	} else {
	    $host2 = $_POST['host2'];
	}

	$oldSite = $_POST['site1'];
	if (empty($_POST['site2'])) {
	    $newSite = $oldSite;
	} else {
	    $newSite = $_POST['site2'];
	}

	$uname = $_POST['username1'];
	if (empty($_POST['username2'])) {
	    $uname2 = $uname;
	} else {
	    $uname2 = $_POST['username2'];
	}

	$pass = $_POST['password1'];
	if (empty($_POST['password2'])) {
	    $pass2 = $pass;
	} else {
	    $pass2 = $_POST['password2'];
	}

	$oldPath = $_POST['folder1'];
	if (empty($_POST['folder2'])) {
	    $newPath = $oldPath;
	} else {
	    $newPath = $_POST['folder2'];
	}

	if (!empty($_POST['copytype'])) $copytype = $_POST['copytype'];
	if (!empty($_POST['skipPattern'])) $skipPattern = $_POST['skipPattern'];
	if (!empty($_POST['dryrun'])) $dryrun = $_POST['dryrun'];
	if (!empty($_POST['verbose'])) $verbose++;
	if (!empty($_POST['continue'])) $exit_on_error = 0;

	if ($host == $host2 && $oldSite == $newSite && $oldPath == $newPath) {
	    echo "<p>*** Sorry, you can't copy a folder over top of itself.</p><hr>\n";
	    return false;
	}
	return true;
    } else {
	echo "<p>*** Sorry, you did something wrong.</p><hr>\n";
	return false;
    }
}


function showForm() {
    global $host, $host2;
    global $uname, $uname2;
    global $pass, $pass2;
    global $oldPath, $newPath;
    global $oldSite, $newSite;
    global $skipPattern;
    global $copytype, $dryrun, $verbose;
    global $environments, $exit_on_error;

    #
    # set up the form
    #
    $env1_str = $env2_str = '';
    foreach ($environments as $name => $hostname) {
	$select_str = '';
	if ($host == $hostname) {
	    $select_str = "selected='selected'";
	}
	$env1_str .= "<option value='$hostname' $select_str>$name</option>\n";
	$select_str = '';
	if ($host2 == $hostname) {
	    $select_str = "selected='selected'";
	}
	$env2_str .= "<option value='$hostname' $select_str>$name</option>\n";
    }
    $copytype_str = '';
    $types = array(
	'folder', 'site',
	'block', 'file', 'page', 'reference', 'format', 'symlink', 'template',
	'assetFactoryContainer', 'contentTypeContainer',
	'metadataSetContainer', 'pageConfigurationSetContainer', 'publishSetContainer',
	'structuredDataDefinitionContainer', 'workflowDefinitionContainer',
	'assetFactory', 'contentType',
	'metaDataSet', 'pageConfigurationSet', 'publishSet',
	'structuredDataDefinition', 'workflowDefinition',
    );
    if ($oldSite != 'Global' && $newSite != 'Global') { # new "Sites"
      #array_push($types, 'connector');
      #array_push($types, 'transport');
      #array_push($types, 'destination');
    }
    foreach ($types as $type) {
	$ptype = $type;
	$ptype = preg_replace('/([A-Z])/', ' $1', $ptype);
	$ptype = ucfirst($ptype);
	if ($type == $copytype) {
	    $copytype_str .= "<option value='$type' selected='selected'>$ptype</option>\n";
	} else {
	    $copytype_str .= "<option value='$type'>$ptype</option>\n";
	}
    }
    $dryrun_str = '';
    if ($dryrun) {
	$dryrun_str = 'checked="checked"';
    }
    $verbose_str = '';
    if ($verbose>2) {
	$verbose_str = 'checked="checked"';
    }
    $continue_str = '';
    if ($exit_on_error == '0') {
	$continue_str = 'checked="checked"';
    }
    $site2_str = '';
    if ($newSite && $newSite != $oldSite) {
	$site2_str = "value='$newSite'";
    }
    $username2_str = '';
    if ($uname2 && $uname2 != $uname) {
	$username2_str = "value='$uname2'";
    }
    $pass2_str = '';
    if ($pass2 && $pass2 != $pass) {
	$pass2_str = "value='$pass2'";
    }

    echo <<<END
<html>
<head>
<title>Copy assets between sites or environments</title>
</head>
<body>
<h1>Copy Site or Folder</h1>
<form method="post">
<table border="0">
<tr><td></td><th align="left">Source</th><th align="left">Destination</th></tr>
<tr>
<th align="left">Environment</th>
<td>
    <select name="host1">
    <option value=""> -- please select -- </option>
    $env1_str
    </select><br />
</td><td>
    <select name="host2">
    <option value=""> -- please select -- </option>
    $env2_str
    </select><br />
</td></tr>
<tr><th align="left">Site</th>
<td>
    <input type="text" name="site1" value="$oldSite" />
    
</td>
<td>
    <input type="text" name="site2" $site2_str />
    <span>&lt;sitename&gt; or <em>Global</em> to copy from/to the Global area</span>
</td>
</tr>
<tr><th align="left"> Web Services Username</th>
<td>
    <input type="text" name="username1" value="$uname" />
</td><td>
    <input type="text" name="username2" $username2_str />
</td></tr>
<tr><th align="left">Password</th>
<td>
    <input type="password" name="password1" value="$pass" />
</td><td>
    <input type="password" name="password2" $pass2_str />
</td></tr>
<tr><th align="left">Folder/Container</th>
<td colspan="2">
    <input type="text" name="folder1" value="$oldPath" />
<!--
</td><td>
    <input type="text" name="folder2" value="$newPath" />
-->
</td></tr>
<tr><th align="left">Type of Asset to Copy</th>
<td colspan="2">
    <select name="copytype">
    $copytype_str
    </select><br />
</td></tr>
<tr><th align="left">Assets to Skip</th>
<td colspan="2">
    <input type="text" name="skipPattern" value="$skipPattern" />
    <em>(e.g. images/ or .mp3)<br />
</td></tr>
<tr><th align="left">Options</th>
<td colspan="2">
    <input type="checkbox" name="dryrun" value="1" $dryrun_str />Dry Run<br />
    <input type="checkbox" name="verbose" value="3" $verbose_str />Verbose<br />
    <input type="checkbox" name="continue" value="1" $continue_str />Persevere in the face of adversity<br />
</td></tr>
<tr><td></td>
<td> <input type="submit" value="Submit" />
</td></tr>

</table>
</form>

<p><strong>Note:</strong>
We follow dependencies when copying, and may need to copy more than you'd think.
For example, when copying a folder, 
we may need to copy the folder's metadata Set, or groups that have access to that folder.
When copying a group, we may need to copy its base asset factory, and so on.
</p>

<p>
With <strong>Type=Folder</strong> we copy the folder and its contents.<br/>
With <strong>Type=Site</strong> we also copy admin containers with
the same name as the folder.<br/>
With <strong>Dry Run</strong> we report what needs to be done, but don't actually copy anything.<br/>
With <strong>Verbose</strong> we report in more detail.<br/>
With <strong>Persevere...</strong> we try to ignore errors and continue copying.<br/>
</p>

<p>To copy an entire site, first create an empty site, then run this script, setting Type to <strong>Site</strong> and Folder/Container to <strong>/</strong>.
</p>

<p>
You only need to specify the destination username, password and site if they differ from the source.
</p>
<hr/>
END;

}


function cleanexit() {
    global $dryrun;

    if ($dryrun) {
      echo "Dry Run Aborted.\n";
    } else {
      echo "Aborted.\n";
    }
    echo "</body></html>";
    exit;
}


function cleanup($xml) {
      $xml = preg_replace('/>/', ">\n", $xml);
      return htmlspecialchars($xml);
}


?>

