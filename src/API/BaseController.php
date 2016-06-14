<?php
namespace PhalconRest\API;

use Phalcon\DI;
use Phalcon\DI\Injectable;
use \PhalconRest\Util\HTTPException;

/**
 * \Phalcon\Mvc\Controller has a final __construct() method, so we can't
 * extend the constructor (which we will need for our RESTController).
 * Thus we extend DI\Injectable instead.
 *
 *
 * Responsible for handling various REST requests
 * Will load the correct model and entity and perform the correct action
 */
class BaseController extends Injectable
{

    /**
     * Store the default entity here
     *
     * @var \PhalconRest\API\Entity
     */
    protected $entity = FALSE;

    /**
     * Store the default model here
     *
     * @var \PhalconRest\API\BaseModel
     */
    protected $model = FALSE;

    /**
     * the name of the controller
     * derived from inflection
     *
     * @var string
     */
    public $singularName = null;

    /**
     * plural version of controller name
     * used for Ember compatible rest returns
     *
     * @var string
     */
    public $pluralName = null;

    /**
     * Constructor, calls the parse method for the query string by default.
     *
     * @param boolean $parseQueryString
     *            true Can be set to false if a controller needs to be called
     *            from a different controller, bypassing the $allowedFields parse
     */
    public function __construct($parseQueryString = true)
    {
        $di = DI::getDefault();
        $this->setDI($di);
        // initialize entity and set to class property
        $this->getEntity();
    }

    /**
     * Load a default model unless one is already in place
     * return the currently loaded model
     *
     * @param string|bool $modelNameString
     * @return BaseModel
     */
    public function getModel($modelNameString = false)
    {
        if ($this->model == false) {
            $config = $this->getDI()->get('config');
            // auto load model so we can inject it into the entity
            if (! $modelNameString) {
                $modelNameString = $this->getControllerName();
            }
            
            $modelName = $config['namespaces']['models'] . $modelNameString;
            $this->model = new $modelName($this->di);
        }
        return $this->model;
    }

    /**
     * Load a default entity unless one is already in place
     * return the currentlyloaded entity
     *
     * @return \PhalconRest\API\Entity
     */
    public function getEntity()
    {
        if ($this->entity == false) {
            $config = $this->getDI()->get('config');
            $model = $this->getModel();
            $searchHelper = new SearchHelper();
            $entity = $config['namespaces']['entities'] . $this->getControllerName('singular') . 'Entity';
            $this->entity = new $entity($model, $searchHelper);
        }
        return $this->entity;
    }

    /**
     * get the controllers singular or plural name
     *
     * @param string $type            
     * @return string|bool
     */
    public function getControllerName($type = 'plural')
    {
        if ($type == 'singular') {
            // auto calc if not already set
            if ($this->singularName == NULL) {
                $className = get_called_class();
                $config = $this->getDI()->get('config');
                $className = str_replace($config['namespaces']['controllers'], '', $className);
                $className = str_replace('Controller', '', $className);
                $this->singularName = $className;
            }
            return $this->singularName;
        } elseif ($type == 'plural') {
            // auto calc most common plural
            if ($this->pluralName == NULL) {
                // this could be better, just adding an s by default
                $this->pluralName = $this->getControllerName('singular') . 's';
            }
            return $this->pluralName;
        }
        
        // todo throw error here
        return false;
    }

    /**
     * catches incoming requests for groups of records
     *
     * @return array Results formated by respond()
     */
    public function get()
    {
        $search_result = $this->entity->find();
        return $this->respond($search_result);
    }

    /**
     * run a limited query for one record
     * bypass nearly all normal search params and just search by the primary key
     *
     * @param int $id
     * @return array
     * @throws HTTPException
     */
    public function getOne($id)
    {
        $search_result = $this->entity->findFirst($id);
        
        if ($search_result == false) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException('Resource not available.', 404, array(
                'dev' => 'The resource you requested is not available.',
                'code' => '43758093745021'
            ));
        } else {
            return $this->respond($search_result);
        }
    }

    /**
     * Attempt to save a record from POST
     * This should be saving a new record
     * @return mixed return valid Apache code, could be an error, maybe not
     * @throws HTTPException
     */
    public function post()
    {
        $request = $this->getDI()->get('request');
        $post = $request->getJson($this->getControllerName('singular'));
        
        // filter out any block columns from the posted data
        $blockFields = $this->model->getBlockColumns();
        foreach ($blockFields as $key => $value) {
            unset($post->$value);
        }
        
        $post = $this->beforeSave($post);
        // This record only must be created
        $id = $this->entity->save($post);
        $this->afterSave($post, $id);
        
        // now fetch the record so we can return it
        $search_result = $this->entity->findFirst($id);
        
        if ($search_result == false) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException('There was an error retrieving the newly created record.', 500, array(
                'dev' => 'The resource you requested is not available after it was just created',
                'code' => '1238510381861'
            ));
        } else {
            return $this->respond($search_result);
        }
    }

    /**
     * Pass through to entity so it can perform extra logic if needed
     * most of the time...
     *
     * @param int $id            
     * @return mixed return valid Apache code, could be an error, maybe not
     */
    public function delete($id)
    {
        $this->beforeDelete($id);
        $this->entity->delete($id);
        $this->afterDelete($id);
    }

    /**
     * read in a resource and update it
     *
     * @param int $id
     * @return array
     * @throws HTTPException
     */
    public function put($id)
    {
        $request = $this->getDI()->get('request');
        // load up the expected object based on the controller name
        $put = $request->getJson($this->getControllerName('singular'));
        
        // filter out any block columns from the posted data
        $blockFields = $this->model->getBlockColumns();
        foreach ($blockFields as $key => $value) {
            unset($put->$value);
        }
        
        if (! $put) {
            throw new HTTPException("There was an error updating an existing record.", 500, array(
                'dev' => "Invalid data posted to the server",
                'code' => '568136818916816'
            ));
        }
        $put = $this->beforeSave($put, $id);
        $id = $this->entity->save($put, $id);
        $this->afterSave($put, $id);
        
        // reload record so we can return it
        $search_result = $this->entity->findFirst($id);
        if ($search_result == false) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException("Could not find newly updated record.", 500, array(
                'dev' => 'The resource you requested is not available.',
                'code' => '6816168161681'
            ));
        } else {
            return $this->respond($search_result);
        }
    }

    /**
     * hook to be run before a controller calls it's save action
     * make it easier to extend default save logic
     *
     * @param mixed $object the data submitted to the server
     * @param int|null $id the pkid of the record to be updated, otherwise null on inserts
     * @return mixed
     */
    public function beforeSave($object, $id = null)
    {
        // extend me in child class
        return $object;
    }

    /**
     * hook to be run after a controller completes it's save logic
     * make it easier to extend default save logic
     *
     * @param mixed $object the data submitted to the server (not a model)
     * @param int|null $id the pkid of the record to be updated or inserted
     */
    public function afterSave($object, $id)
    {
        // extend me in child class
    }

    /**
     * hook to be run before a controller performs delete logic
     * make it easier to extend default delete logic
     *
     * @param int $id the record to be deleted
     */
    public function beforeDelete($id)
    {
        // extend me in child class
    }

    /**
     * hook to be run after a controller performs delete logic
     * make it easier to extend default delete logic
     *
     * @param int $id the id of the record that was just removed
     */
    public function afterDelete($id)
    {
        // extend me in child class
    }

    /**
     *
     * @param int $id
     * @return array
     */
    public function patch($id)
    {
        return array(
            'Patch / stub'
        );
    }

    /**
     * Provides a base CORS policy for routes like '/users' that represent a Resource's base url
     * Origin is allowed from all urls.
     * Setting it here using the Origin header from the request
     * allows multiple Origins to be served. It is done this way instead of with a wildcard '*'
     * because wildcard requests are not supported when a request needs credentials.
     *
     * @return true
     */
    public function optionsBase()
    {
        $response = $this->getDI()->get('response');
        
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, HEAD');
        $response->setHeader('Access-Control-Allow-Origin', $this->getDI()
            ->get('request')
            ->getHeader('Origin'));
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Allow-Headers', "origin, x-requested-with, content-type");
        $response->setHeader('Access-Control-Max-Age', '86400');
        return true;
    }

    /**
     * Provides a CORS policy for routes like '/users/123' that represent a specific resource
     *
     * @return true
     */
    public function optionsOne()
    {
        $response = $this->getDI()->get('response');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, PUT, PATCH, DELETE, OPTIONS, HEAD');
        $response->setHeader('Access-Control-Allow-Origin', $this->getDI()
            ->get('request')
            ->getHeader('Origin'));
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Allow-Headers', "origin, x-requested-with, content-type");
        $response->setHeader('Access-Control-Max-Age', '86400');
        return true;
    }

    /**
     * Should be called by methods in the controllers that need to output results to the HTTP Response.
     * Ensures that arrays conform to the patterns required by the Response objects.
     *
     * appends the controller name to each since that is what ember wants
     * that logic should probably sit in the JSONReponse object but I'm not sure how to infer the controllers name from there
     * maybe check in bootstrap...$app->after() to see if you can access the current controller?
     *
     * @param array $recordsResult records to format as return output
     * @return array Output array. If there are records (even 1), every record will be an array ex: array(array('id'=>1),array('id'=>2))
     * @throws HTTPException
     */
    protected function respond($recordsResult)
    {
        if (! is_array($recordsResult)) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException("An error occured while retrieving records.", 500, array(
                'dev' => 'The records returned were malformed.',
                'code' => '861681684364'
            ));
        }
        
        // modify results based on the number of records returned
        $rowCount = count($recordsResult);
        switch ($rowCount) {
            // No records returned, so return an empty array
            case 0:
                return array();

            // return single record within an array
            case 1:
                if (isset($recordsResult['meta'])) {
                    return $recordsResult;
                } else {
                    $recordsResult[0]; //FIXME: sounds like a bug? this should be returned?
                }

            default:
                return $recordsResult;
                break;
        }
    }
}