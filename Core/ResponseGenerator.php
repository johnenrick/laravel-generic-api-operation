<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ResponseGenerator extends Controller
{
    private $success = false;
    private $fail = false;
    private $response = [];
    private $totalResult = null;
    private $debugMessages = [];
    public function __construct(){

    }
    public function setSuccess($success){
      $this->success = $success;
    }
    public function setFail($fail){
      $this->fail = $fail;
    }
    public function setTotalResult($totalResult){
      $this->totalResult = $totalResult;
    }
    public function addDebug($name, $message){
      $this->debugMessages[$name] = $message;
    }
    public function generate(){
      if($this->fail){
        $this->response = ['error' => $this->fail];
        $this->response['debug'] = $this->debugMessages;
        $errorCode = 422;
        switch($this->fail['code'] * 1){
          case 2:
            $errorCode = 401;
        }
        return response()->json($this->response, $errorCode);

      }else{
        $this->response = ['data'=> $this->success];
        $this->response['debug'] = $this->debugMessages;
        $this->response['debug']['add'] = $this->totalResult;
        $this->response['additional_data'] = [];


        ($this->totalResult) ? $this->response['additional_data']['total_result'] = $this->totalResult : null;
        return response()->json($this->response, 200);
      }
    }
}
