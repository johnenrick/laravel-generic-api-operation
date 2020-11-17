<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Core\GenericCreate;
use Core\GenericSync;
use Core\GenericFormValidation;
use Illuminate\Support\Facades\Validator;
use Core\TableStructure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Request as requester;
use App;

class GenericController extends Controller
{
    /***
    Define the table structure. It defines the validation, foreign tables, aliased table, etc.
    e.g.
    tableStructure = [
      columns => [
        column_1 => [
          validation => 'required|alpha'
        ],
        column_2 => [

        ]
      ],
      foreign_table => [
        child_table => [
          is_required: true,
          columns: []
        ]s
      ]
    ]
    */
    public $tableStructure = [];
    public $model;
    public $responseGenerator;
    public $user = [];
    public $deleteWithUserId = false;
    public $basicOperationAuthRequired = [
      "create" => false,
      "retrieve" => false,
      "update" => false,
      "delete" => false,
    ];
    public $retrieveCustomQueryModel = null;
    public function initGenericController(){
      $this->tableStructure = (new Core\TableStructure($this->tableStructure, $this->model))->getStructure();
      $this->responseGenerator = new Core\ResponseGenerator();

      if(config()->set('payload') == null && requester::input('PAYLOAD')){
        config()->set('payload', requester::input('PAYLOAD'));
      }else{
        config()->set('payload', [
          'id' => 7,
          'company_id' => 7,
          'roles.100' => true
        ]); // set sample config
      }
      // $this->responseGenerator->addDebug('request', requester::input());
      $this->responseGenerator->addDebug('payload', config('payload'));
      $this->responseGenerator->addDebug('user_session', $this->userSession(null));
    }
    public function systemGenerateRetrieveParameter($data){
      return $data;
    }
    /**
      Default create resource. Execute create operation.
      Parameters
        $request Request required the request object
    */
    public function create(Request $request){
      $requestData = $request->all();
      $resultObject = $this->createUpdateEntry($requestData);
      $this->responseGenerator->setSuccess($resultObject['success']);
      $this->responseGenerator->setFail($resultObject['fail']);
      return $this->responseGenerator->generate();
    }
    public function retrieve(Request $request){
      // printR($request->all());
      $requestArray = $this->systemGenerateRetrieveParameter($request->all());
      $validator = Validator::make($requestArray, ["select" => "required|array|min:1"]);
      if($validator->fails()){
        $this->responseGenerator->setFail([
          "code" => 1,
          "message" => $validator->errors()->toArray()
        ]);
        return $this->responseGenerator->generate();
      }
      if(!$this->checkAuthenticationRequirement($this->basicOperationAuthRequired["retrieve"])){
        return $this->responseGenerator->generate();
      }
      $genericRetrieve = new Core\GenericRetrieve($this->tableStructure, $this->model, $requestArray, $this->retrieveCustomQueryModel);
      $this->responseGenerator->setSuccess($genericRetrieve->executeQuery());
      if($genericRetrieve->totalResult != null){
        $this->responseGenerator->setTotalResult($genericRetrieve->totalResult);
      }
      return $this->responseGenerator->generate();
    }

    public function update(Request $request){
      if(!$this->checkAuthenticationRequirement($this->basicOperationAuthRequired["update"])){
        return $this->responseGenerator->generate();
      }
      $requestData = $request->all();
      $resultObject = $this->createUpdateEntry($requestData, "update");
      $this->responseGenerator->setSuccess($resultObject['success']);
      $this->responseGenerator->setFail($resultObject['fail']);
      return $this->responseGenerator->generate();
    }
    public function delete(Request $request){
      $requestData = $request->all();
      $resultObject = $this->deleteEntry($requestData['id'], isset($requestData['condition']) ? $requestData['condition'] : null);
      $this->responseGenerator->setSuccess($resultObject['success']);
      $this->responseGenerator->setFail($resultObject['fail']);
      return $this->responseGenerator->generate();
    }
    public function createUpdateEntry($entry, $operation = "create"){
      $resultObject = [
        "success" => false,
        "fail" => false
      ];
      $validation = new Core\GenericFormValidation($this->tableStructure, $operation);

      if($validation->isValid($entry)){
        if($operation == "update"){
          $genericUpdate = new Core\GenericUpdate($this->tableStructure, $this->model);
          $resultObject['success'] = $genericUpdate->update($entry);
        }else{
          $genericCreate = new Core\GenericCreate($this->tableStructure, $this->model);
          $resultObject['success'] = $genericCreate->create($entry);
        }
      }else{
        $resultObject['fail'] = [
          "code" => 1,
          "message" => $validation->validationErrors
        ];

      }
      return $resultObject;
    }
    public function deleteEntry($id, $condition = null){
      $resultObject = [
        "success" => false,
        "fail" => false
      ];
      if($this->deleteWithUserId){
        $condition = [[
          'column' => 'user_id',
          'value' => $this->userSession()
        ]];
      }
      $genericDelete = new Core\GenericDelete($this->tableStructure, $this->model);
      $resultObject['success'] = $genericDelete->delete($id, $condition);
      return $resultObject;
    }

    public function syncEntries($entries, $overrideValues = []){
      $genericSync = new Core\GenericSync($this->tableStructure, $this->model);
      return $genericSync->sync($entries, $overrideValues);
    }

    public function validator($data, $rules){
      $validation = new Core\GenericFormValidation($this->tableStructure);
      return Validator::make($data, $rules);
    }
    public function checkAuthenticationRequirement($authRequired = true){
      if($authRequired && !auth()->user()){
        $this->responseGenerator->setFail([
          "code" => 2,
          "message" => "Not logged in"
        ]);
        return false;
      }else{
        if($authRequired){
          $this->user = auth()->user()->toArray();
        }
        return true;
      }
    }
    public function requestUploadTicket($quantity, $remarks){
      try {
        $param = [
          "expected_file_quantity" => $quantity,
          "note" => $remarks
        ];
        $client = new Client(); //GuzzleHttp\Client
          $result = $client->request('POST', env('FILE_SERVER').'/v1/get-ticket', [
          'json' => $param
        ]);
        $result = json_decode((string)$result->getBody(), true);
        $resultObject = ['upload_ticket_id' => $result['data']['id'], 'upload_location' => $result['data']['location']];
        return $resultObject;
      } catch (GuzzleException $e) {
        if(!$e->getResponse()){
          $this->responseGenerator->addDebug('linkgetenv', getenv('FILE_SERVER').'/v1/get-ticket');
          $this->responseGenerator->addDebug('linkenv', env('FILE_SERVER').'/v1/get-ticket');
        }else if($e->getResponse()->getStatusCode() == 422){ // validation error
          $response = json_decode((string)$e->getResponse()->getBody(), true);
          $this->responseGenerator->setFail(['code' => 422, "message" => $response]);
        }
        return false;
      }
    }

    public function userSession($key = "id"){
      if($key){
        if(config('payload.'.$key)){
          return config('payload.'.$key);
        }else{
          $config = config('payload');
          return isset($config[$key]) ? $config[$key] : null;
        }
      }else{
        return config('payload');
      }
    }
    public function test(){
      return "API exists";
    }
}
