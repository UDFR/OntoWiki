<?php

/**
 * OntoWiki resource controller.
 *
 * @package    application
 * @subpackage mvc
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
class ResourceController extends OntoWiki_Controller_Base {
    private function _addLastModifiedHeader() {
        $r = $this->_owApp->selectedResource;
        $m = $this->_owApp->selectedModel;

        if (!$m || !$r) {
            return;
        }

        $versioning   = Erfurt_App::getInstance()->getVersioning();
        $lastModArray = $versioning->getLastModifiedForResource($r, $m->getModelUri());

        if (null === $lastModArray || !is_numeric($lastModArray['tstamp'])) {
            return;
        }

        $response = $this->getResponse();
        $response->setHeader('Last-Modified', date('r', $lastModArray['tstamp']), true);
    }
    
    // Check setup for table exist or not if no create it
	private function _checkSetup() {
        $this->_initialize();
    } 

    private function _initialize()
    {
    	
            $getstore = Erfurt_App::getInstance()->getStore();
        
        if (!$getstore->isSqlSupported()) {
            throw new Exception('For create table store adapter needs to implement the SQL interface.');
        }
        
        $existingTableNames = $getstore->listTables();
		//var_dump($existingTableNames); exit();
        if (!in_array('ef_reviews', $existingTableNames)) {
            $columnSpec = array(
                'id'          => 'INT PRIMARY KEY AUTO_INCREMENT',
                'model'       => 'VARCHAR(255) NOT NULL',
                's'     => 'VARCHAR(255) NOT NULL',
                'p'    => 'VARCHAR(255) NOT NULL',
                'o'      => 'VARCHAR(255) NOT NULL',
                'review_flag' => 'INT NOT NULL'
                );
            
            $getstore->createTable('ef_reviews', $columnSpec);
        }
    }

    /**
     * Displays all preoperties and values for a resource, denoted by parameter
     */
    public function propertiesAction() {
    	
    	$this->_checkSetup();
        $this->_addLastModifiedHeader();

        $store      = $this->_owApp->erfurt->getStore();
        $graph      = $this->_owApp->selectedModel;
        $resource   = $this->_owApp->selectedResource;
        $navigation = $this->_owApp->navigation;
        $translate  = $this->_owApp->translate;

        // add export formats to resource menu
        $resourceMenu = OntoWiki_Menu_Registry::getInstance()->getMenu('resource');
        foreach (array_reverse(Erfurt_Syntax_RdfSerializer::getSupportedFormats()) as $key => $format) {
            $resourceMenu->prependEntry(
                    'Export Resource as ' . $format,
                    $this->_config->urlBase . 'resource/export/f/' . $key . '?r=' . urlencode($resource)
            );
        }

        $menu = new OntoWiki_Menu();
        $menu->setEntry('Resource', $resourceMenu);

        $event = new Erfurt_Event('onCreateMenu');
        $event->menu = $resourceMenu;
        $event->resource = $this->_owApp->selectedResource;
        $event->model = $this->_owApp->selectedModel;
        $event->trigger();

        $event = new Erfurt_Event('onPropertiesAction');
        $event->uri = (string)$resource;
        $event->graph = $this->_owApp->selectedModel->getModelUri();
        $event->trigger();

        // Give plugins a chance to add entries to the menu
        $this->view->placeholder('main.window.menu')->set($menu->toArray(false, true));

        $title = $resource->getTitle($this->_config->languages->locale) 
               ? $resource->getTitle($this->_config->languages->locale) 
               : OntoWiki_Utils::contractNamespace((string)$resource);
        $windowTitle = sprintf($translate->_('Properties of %1$s'), $title);
        $this->view->placeholder('main.window.title')->set($windowTitle);

        if (!empty($resource)) {
            $event = new Erfurt_Event('onPreTabsContentAction');
            $event->uri = (string)$resource;
            $result = $event->trigger();

            if ($result) {
                $this->view->preTabsContent = $result;
            }

            $event = new Erfurt_Event('onPrePropertiesContentAction');
            $event->uri = (string)$resource;
            $result = $event->trigger();

            if ($result) {
                $this->view->prePropertiesContent = $result;
            }

            $model = new OntoWiki_Model_Resource($store, $graph, (string)$resource);
            $values = $model->getValues();
            $predicates = $model->getPredicates();

            // new trigger onPropertiesActionData to work with data (reorder with plugin)
            $event = new Erfurt_Event('onPropertiesActionData');
            $event->uri         = (string)$resource;
            $event->predicates  = $predicates;
            $event->values      = $values;
            $result = $event->trigger();

            if ( $result ) {
                $predicates = $event->predicates;
                $values     = $event->values;
            }

            $titleHelper = new OntoWiki_Model_TitleHelper($graph);
            // add graphs
            $graphs = array_keys($predicates);
            $titleHelper->addResources($graphs);

            // set RDFa widgets update info for editable graphs and other graph info
            $graphInfo = array();
            $editableFlags = array();
            foreach ($graphs as $g) {
                $graphInfo[$g] = $titleHelper->getTitle($g, $this->_config->languages->locale);

                if ($this->_erfurt->getAc()->isModelAllowed('edit', $g)) {
                    $editableFlags[$g] = true;
                    $this->view->placeholder('update')->append(array(
                        'sourceGraph'    => $g,
                        'queryEndpoint'  => $this->_config->urlBase . 'sparql/',
                        'updateEndpoint' => $this->_config->urlBase . 'update/'
                    ));
                } else {
                    $editableFlags[$g] = false;
                }
            }
            
            $this->view->graphs        = $graphInfo;
            $this->view->editableFlags = $editableFlags;
            $this->view->values        = $values;
            $this->view->predicates    = $predicates;
            $this->view->resourceUri   = (string)$resource;
            $this->view->graphUri      = $graph->getModelIri();
            $this->view->graphBaseUri  = $graph->getBaseIri();
            $this->view->editable = false; // use $this->editableFlags[$graph] now
            // prepare namespaces
            $namespaces = $graph->getNamespaces();
            $graphBase  = $graph->getBaseUri();
            // Abhi - Review image validators
	    	$this->view->store         = $store;
	    	$this->view->url           = $this->_config->staticUrlBase;
            if (!array_key_exists($graphBase, $namespaces)) {
                $namespaces = array_merge($namespaces, array($graphBase => OntoWiki_Utils::DEFAULT_BASE));
            }
            $this->view->namespaces = $namespaces;
        }

        $toolbar = $this->_owApp->toolbar;
        
        /*UDFR- Abhi- Add 'Save Review' button if Reviewer Action is granted */
		if ($this->_erfurt->getAc()->isActionAllowed('Reviewer')) {
            $this->view->review = true;
            // adding submit button for review-action
	    	$toolbar->appendButton(
			OntoWiki_Toolbar::SUBMIT, 
			array('name' => 'Save Review', 'id' => 'resource-review')
			);
		}
		else {
            $this->view->review = false;
        }
        
		//UDFR- Abhi- view variables for review action
		$reviewurl = $this->_config->urlBase . 'resource/review';
        $this->view->placeholder('main.window.title')->set($windowTitle);
        $this->view->formActionUrl = (string) $reviewurl;
        $this->view->formMethod    = 'post';
        $this->view->formName      = 'resource-review';
        $this->view->formEncoding  = 'multipart/form-data';
			
        // show only if not forwarded and if model is writeable
        // TODO: why is isEditable not false here?
        if ($this->_request->getParam('action') == 'properties' && $graph->isEditable() &&
                $this->_owApp->erfurt->getAc()->isModelAllowed('edit', $this->_owApp->selectedModel)
        ) {
            // TODO: check acl
            $toolbar->appendButton(OntoWiki_Toolbar::EDIT, array('name' => 'Edit Properties'));

        	$toolbar->appendButton(OntoWiki_Toolbar::EDITADD, array(
                'name'  => 'Clone Resource',
                'class' => 'clone-resource'
            ));
            // ->appendButton(OntoWiki_Toolbar::EDITADD, array('name' => 'Add Property', 'class' => 'property-add'));
            $params = array(
                    'name' => 'Delete',
                    'url'  => $this->_config->urlBase . 'resource/delete/?r=' . urlencode((string)$resource)
            );
            $toolbar->appendButton(OntoWiki_Toolbar::SEPARATOR)
                    ->appendButton(OntoWiki_Toolbar::DELETE, $params);

            //UDFR - Abhi - to activate Inline Add Property functionality uncomment lines below
            //$toolbar->prependButton(OntoWiki_Toolbar::SEPARATOR)
              //      ->prependButton(OntoWiki_Toolbar::ADD, array('name' => 'Add Property', '+class' => 'hidden edit property-add'));


            $toolbar->prependButton(OntoWiki_Toolbar::SEPARATOR)
                    ->prependButton(OntoWiki_Toolbar::CANCEL, array('+class' => 'hidden'))
                    ->prependButton(OntoWiki_Toolbar::SAVE, array('+class' => 'hidden'));
        }

        // let plug-ins add buttons
        $toolbarEvent = new Erfurt_Event('onCreateToolbar');
        $toolbarEvent->resource = (string)$resource;
        $toolbarEvent->graph    = (string)$graph;
        $toolbarEvent->toolbar  = $toolbar;
        $eventResult = $toolbarEvent->trigger();
        
        if ($eventResult instanceof OntoWiki_Toolbar) {
            $toolbar = $eventResult;
        }
        
        // add toolbar
        $this->view->placeholder('main.window.toolbar')->set($toolbar);

        //show modules
        $this->addModuleContext('main.window.properties');
    }

    /**
     * UDFR- Abhi - Stores reviewed properties in to ef_reviews table
     * 	            Stores review actions in to ef_versioning_action table
     */
    public function reviewAction() {
		$flag=1;
		$resource   = $this->_owApp->selectedResource;
		$redirectUrl = $this->_config->urlBase . 'resource/properties/?r=' . urlencode($resource);
		$redirect  = $this->_request->getParam('redirect', $redirectUrl);
		$store      = $this->_owApp->erfurt->getStore();
    	$graph      = $this->_owApp->selectedModel;
		$modelIri = $graph->getModelIri();
		$baseIri  = $graph->getBaseIri();
		//$resource   = $this->_owApp->selectedResource;
		$resourceUri   = (string)$resource;
		$model = new OntoWiki_Model_Resource($store, $graph, (string)$resource);
        $values = $model->getValues();
		$predicates = $model->getPredicates();
		//var_dump($resource); echo '<br>'; echo '<br>';
		//var_dump($predicates); echo '<br>';echo '<br>'; var_dump($values); exit;
        // get versioning
        $versioning = $this->_erfurt->getVersioning();
	
	
	
      	if($_POST['property_review']){

		foreach($_POST['property_review'] as $val) {

	    //$sql = 'UPDATE ef_stmt SET review_flag = 1 WHERE s = \'' . addslashes($resourceUri) . '\' AND p = \'' . addslashes($val) . '\'';
	    //var_dump($modelIri); exit;
	    //$store->sqlQuery($sql);

	    foreach ($predicates as $graph => $predicatesForGraph) { 

	    	foreach ($predicatesForGraph as $uri => $predicate) {
		    	$currentPredicate = $predicates[$graph][$uri];  
		    	
		    	foreach ($values[$graph][$uri] as $entry) {
					if ($currentPredicate['uri']===$val) {
				    				    
	    			    $event = new Erfurt_Event('onReviewStatement');
			            $event->graphUri   = $modelIri;
			            if ($entry['uri']===NULL) {
				      		$actionsSql = 'INSERT INTO ef_reviews (model, s, p, o, review_flag) VALUES (\''. addslashes($modelIri) . '\', \'' . addslashes($resourceUri) . '\', \'' . addslashes($val) . '\', \'' 
						   . $entry['object'] . '\', ' . (int)$flag .  ')' ;
				       //var_dump($actionsSql); exit;
	    			      $store->sqlQuery($actionsSql);
							$event->statement = array(
									'subject'   => $resourceUri,
					            	'predicate' => $val,
					            	'object'    => array('value'    => $entry['object'], 'type'     => 'literal', 'lang'=> 'en')
					         		);
				    	}
				    	else {
							$actionsSql = 'INSERT INTO ef_reviews (model, s, p, o, review_flag) VALUES (\''. addslashes($modelIri) . '\', \'' .addslashes($resourceUri) . '\', \'' . addslashes($val) . '\', \'' 
						     . $entry['uri'] . '\', ' . (int)$flag .  ')' ;
							//var_dump($actionsSql); exit;
	    			        $store->sqlQuery($actionsSql);
					  		$event->statement = array(
									'subject'   => $resourceUri,
					            	'predicate' => $val,
					            	'object'    => array('value'    => $entry['uri'], 'type'     => 'uri')
					         		);
				    	}
			            $event->trigger();//*/ var_dump ($entry); echo '<br>';
					}
		    	}
	        }
    	}
	    $countReview++;
		} 

	$message = $countReview
                    . ' Statement'. ($countReview != 1 ? 's': '')
                    . ($countReview ? ' successfully' : '')
                    . ' reviewed.';

    $this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::SUCCESS));

	$this->_redirect($redirect, array('code' => 302));
      }
      else { 
		$message = 'No statement was selected. Please select statement(s) for review';

        	$this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::ERROR));
		$this->_redirect($redirect, array('code' => 302));
      }
    }  
    
    /**
     * Displays resources of a certain type and property values that have
     * been selected by the user.
     */
    public function instancesAction() {
        $store       = $this->_owApp->erfurt->getStore();
        $graph       = $this->_owApp->selectedModel;
        $resource    = $this->_owApp->selectedResource;
        $navigation  = $this->_owApp->navigation;
        $translate   = $this->_owApp->translate;

        //the list is setup in Ontowiki/Controller/Plugin/ListSetupHelper.php

        $listHelper = Zend_Controller_Action_HelperBroker::getStaticHelper('List');
        $listName = "instances";
        if($listHelper->listExists($listName)){
            $list = $listHelper->getList($listName);
            $listHelper->addList($listName, $list, $this->view);
        } else {
            if($this->_owApp->selectedModel == null){
                $this->_owApp->appendMessage(new OntoWiki_Message("your session timed out. select a model",  OntoWiki_Message::ERROR));
                $this->_redirect($this->_config->baseUrl);
            }
            $list = new OntoWiki_Model_Instances($store, $this->_owApp->selectedModel, array());
            $listHelper->addListPermanently($listName, $list, $this->view);
        }

        //begin view building
        $this->view->placeholder('main.window.title')->set('Resource List');

        // TODO: check acl
        // build toolbar
        /*
         * toolbar disabled for 0.9.5 (reactived hopefully later :) ) */

            if ($graph->isEditable()) {
                $toolbar = $this->_owApp->toolbar;
                $toolbar->appendButton(OntoWiki_Toolbar::EDITADD, array('name' => 'Add Instance', 'class' => 'init-resource'));
                        // ->appendButton(OntoWiki_Toolbar::EDIT, array('name' => 'Edit Instances', 'class' => 'edit-enable'))
                        // ->appendButton(OntoWiki_Toolbar::SEPARATOR)
                        // ->appendButton(OntoWiki_Toolbar::DELETE, array('name' => 'Delete Selected', 'class' => 'submit'))
                        // ->prependButton(OntoWiki_Toolbar::SEPARATOR)
                        // ->prependButton(OntoWiki_Toolbar::CANCEL)
                        // ->prependButton(OntoWiki_Toolbar::SAVE);
                $this->view->placeholder('main.window.toolbar')->set($toolbar);
            }
        /*
            
            $url = new OntoWiki_Url(
                array(
                    'controller' => 'resource',
                    'action' => 'delete'
                ),
                array()
            );
            
            $this->view->formActionUrl = (string)$url;
            $this->view->formMethod    = 'post';
            $this->view->formName      = 'instancelist';
            $this->view->formEncoding  = 'multipart/form-data';
            *
        */

        $url = new OntoWiki_Url();
        $this->view->redirectUrl = (string)$url;

        $this->addModuleContext('main.window.list');
        $this->addModuleContext('main.window.instances');
    }

    /**
     * Deletes one or more resources denoted by param 'r'
     * TODO: This should be done by a evolution pattern in the future
     */
    public function deleteAction() {
        $this->view->clearModuleCache();

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        $store     = $this->_erfurt->getStore();
        $model     = $this->_owApp->selectedModel;
        $modelIri  = (string) $model;
        $redirect  = $this->_request->getParam('redirect', $this->_config->urlBase);

        if (isset($this->_request->r)) {
            $resources = $this->_request->getParam('r', array());
        } else {
            throw new OntoWiki_Exception('Missing parameter r!');
            exit;
        }

        if (!is_array($resources)) {
            $resources = array($resources);
        }

        // get versioning
        $versioning = $this->_erfurt->getVersioning();

        $count = 0;
        if ($this->_erfurt->getAc()->isModelAllowed('edit', $modelIri)) {
            foreach ($resources as $resource) {

                # if we have only a nice uri, fill to full uri
                if (Zend_Uri::check($resource) == false) {
                    $resource = $model->getBaseIri() . $resource;
                }

                // action spec for versioning
                $actionSpec                 = array();
                $actionSpec['type']         = 130;
                $actionSpec['modeluri']     = $modelIri;
                $actionSpec['resourceuri']  = $resource;

                // starting action
                $versioning->startAction($actionSpec);

                $stmtArray = array();

                // query for all triples to delete them
                $sparqlQuery = new Erfurt_Sparql_SimpleQuery();
                $sparqlQuery->setProloguePart('SELECT ?p, ?o');
                $sparqlQuery->addFrom($modelIri);
                $sparqlQuery->setWherePart('{ <' . $resource . '> ?p ?o . }');

                $result = $store->sparqlQuery($sparqlQuery,array('result_format'=>'extended'));
                // transform them to statement array to be compatible with store methods
                foreach ($result['results']['bindings'] as $stmt) {
                    $stmtArray[$resource][$stmt['p']['value']][] = $stmt['o'];
                }

                $store->deleteMultipleStatements($modelIri, $stmtArray);

                // stopping action
                $versioning->endAction();

                $count++;
            }

            $message = $count
                    . ' resource'. ($count != 1 ? 's': '')
                    . ($count ? ' successfully' : '')
                    . ' deleted.';

            $this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::SUCCESS)
            );

        } else {

            $message = 'not allowed.';

            $this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::WARNING)
            );
        }

        $event = new Erfurt_Event('onDeleteResources');
        $event->resourceArray = $resources;
        $event->modelUri = $modelIri;
        $event->trigger();


        $this->_redirect($redirect, array('code' => 302));
    }

    public function exportAction() {
        $this->_addLastModifiedHeader();

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        if (isset($this->_request->m)) {
            $modelUri = $this->_request->m;
        } else if (isset($this->_owApp->selectedModel)) {
            $modelUri = $this->_owApp->selectedModel->getModelUri();
        } else {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 400 Bad Request');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("No nodel given.");
            exit;
        }

        $resource = $this->getParam('r', true);

        // Check whether the f parameter is given. If not: default to rdf/xml
        if (!isset($this->_request->f)) {
            $format = 'rdfxml';
        } else {
            $format = $this->_request->f;
        }

        $format = Erfurt_Syntax_RdfSerializer::normalizeFormat($format);

        $store = $this->_erfurt->getStore();

        // Check whether given format is supported. If not: 400 Bad Request.
        if (!in_array($format, array_keys(Erfurt_Syntax_RdfSerializer::getSupportedFormats()))) {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 400 Bad Request');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("Format '$format' not supported.");
            exit;
        }

        // Check whether model exists. If not: 404 Not Found.
        if (!$store->isModelAvailable($modelUri, false)) {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 404 Not Found');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("Model '$modelUri' not found.");
            exit;
        }

        // Check whether model is available (with acl). If not: 403 Forbidden.
        if (!$store->isModelAvailable($modelUri)) {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 403 Forbidden');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("Model '$modelUri' not available.");
            exit;
        }

        $filename = 'export' . date('Y-m-d_Hi');

        switch ($format) {
            case 'rdfxml':
                $contentType = 'application/rdf+xml';
                $filename .= '.rdf';
                break;
            case 'rdfn3':
                $contentType = 'text/rdf+n3';
                $filename .= '.n3';
                break;
            case 'rdfjson':
                $contentType = 'application/json';
                $filename .= '.json';
                break;
            case 'turtle':
                $contentType = 'application/x-turtle';
                $filename .= '.ttl';
                break;
        }

        $additional = array();
        if ((isset($this->_request->provenance) && (boolean)$this->_request->provenance)) {
            $bNodeCounter = 1;

            $model = $store->getModel($modelUri);

            $fileUri = 'http://' . $_SERVER['HTTP_HOST'] . $this->_request->getRequestUri();
            $curBNode = '_:node' . $bNodeCounter++;
            $additional[$fileUri] = array(
                    EF_RDF_TYPE => array(array(
                                    'value' => 'http://purl.org/net/provenance/ns#DataItem',
                                    'type' => 'uri'
                            )),
                    'http://purl.org/net/provenance/ns#createdBy' => array(array(
                                    'value' => $curBNode,
                                    'type' => 'bnode'
                            ))
            );
            $additional[$curBNode] = array(
                    EF_RDF_TYPE => array(array(
                                    'value' => 'http://purl.org/net/provenance/ns#DataCreation',
                                    'type' => 'uri'
                            )),
                    'http://purl.org/net/provenance/ns#performedAt' => array(array(
                                    'type' => 'literal',
                                    'value' => date('c'),
                                    'datatype' => EF_XSD_DATETIME
                            )),
                    'http://purl.org/net/provenance/ns#performedBy' => array(array(
                                    'value' => '_:node'.(++$bNodeCounter),
                                    'type' => 'bnode'
                            ))
            );
            $curBNode = '_:node'.$bNodeCounter++;

            $additional[$curBNode] = array(
                    EF_RDF_TYPE => array(array(
                                    'value' => 'http://purl.org/net/provenance/types#DataCreatingService',
                                    'type' => 'uri'
                            )),
                    'http://www.w3.org/2000/01/rdf-schema#comment' => array(array(
                                    'type' => 'literal',
                                    'value' => 'OntoWiki v0.95 (http://ontowiki.net)'
                            ))
            );

            $s = $resource;
            $operatorUri = $model->getOption('http://purl.org/net/provenance/ns#operatedBy');
            if ($operatorUri !== null) {
                $additional[$s] = array(
                        'http://purl.org/net/provenance/ns#operatedBy' => array(array(
                                        'type' => 'uri',
                                        'value' => $operatorUri[0]['value']
                                ))
                );
            } else {
                $additional[$s] = array();
            }

            $versioning = Erfurt_App::getInstance()->getVersioning();
            $history = $versioning->getHistoryForResource($resource, $modelUri);

            foreach ($history as $i=>$hItem) {
                $curBNode = '_:node' . $bNodeCounter++;

                $additional[$s]['http://purl.org/net/provenance/ns#CreatedBy'] = array(array(
                                'type' => 'bnode',
                                'value' => $curBNode
                ));

                $additional[$curBNode] = array(
                        EF_RDF_TYPE => array(array(
                                        'type' => 'uri',
                                        'value' => 'http://purl.org/net/provenance/ns#DataCreation'
                                )),
                        'http://purl.org/net/provenance/ns#performedAt' => array(array(
                                        'type' => 'literal',
                                        'value' => date('c', $hItem['tstamp']),
                                        'datatype' => EF_XSD_DATETIME
                                )),
                        'http://purl.org/net/provenance/ns#performedBy' => array(array(
                                        'type' => 'uri',
                                        'value' => $hItem['useruri']
                                ))
                );

                if ($i<(count($history)-1)) {
                    $additional[$curBNode]['http://purl.org/net/provenance/ns#precededBy'] = array(array(
                                    'type' => 'bnode',
                                    'value' => '_:node' . ($bNodeCounter+1)
                    ));
                }

                $s = '_:node'.$bNodeCounter++;
            }
        }

        // Event
        $event = new Erfurt_Event('beforeExportResource');
        $event->resource = $resource;
        $event->modelUri = $modelUri;
        $additional2 = $event->trigger();

        if (is_array($additional2)) {
            $additional = array_merge($additional, $additional2);
        }

        $response = $this->getResponse();
        $response->setHeader('Content-Type', $contentType, true);
        $response->setHeader('Content-Disposition', ('filename="'.$filename.'"'));

        $serializer = Erfurt_Syntax_RdfSerializer::rdfSerializerWithFormat($format);
        echo $serializer->serializeResourceToString($resource, $modelUri, false, true, $additional);
        $response->sendResponse();
        exit;
    }
}
