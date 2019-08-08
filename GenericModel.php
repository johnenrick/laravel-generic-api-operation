<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class GenericModel extends Model
{
  use SoftDeletes;
  public $parent = [];
  protected $validationRules = []; // model level validation rule
  protected $validationRuleNotRequired = [];
  private $defaultValidationInitialized = false;
  protected $defaultValue = [];
  protected $formulatedColumn = [];
  public $useSessionCompanyID = true;
  // public function children($childName){
  //   $joinedTable = $this;
  //   foreach($this->children as $childName => $child){
  //     $joinedTable = $joinedTable
  //   }
  //   return $joinedTable;
  // }
  public function getValidationRule(){
    if(!$this->defaultValidationInitialized){
      $this->initDefaultValidation();
    }
    return $this->validationRules;
  }
  public function getFormulatedColumn(){
    return $this->formulatedColumn;
  }
  public function systemGenerateValue($data){
    return $data;
  }
  public function initDefaultValidation(){
    $tableColumns = $this->getTableColumns();
    foreach($tableColumns as $tableColumn){
      $isNotRequired = in_array($tableColumn, $this->validationRuleNotRequired) || $tableColumn == 'company_id';
      if(!isset($this->validationRules[$tableColumn]) && !$isNotRequired){ // set required as default for no validation specified
        $this->validationRules[$tableColumn] = 'required';
      }else if(!isset($this->validationRules[$tableColumn]) && $isNotRequired ){
        $this->validationRules[$tableColumn] = '';
      }
      $rules = explode('|', $this->validationRules[$tableColumn]);

      foreach($rules as $ruleIndex => $rule){
        $explodedRuleString = explode(':', $rule); // separate rule and parameter
        switch($explodedRuleString[0]){
          case 'enum':
            $enumList = $this->getPossibleEnumValues($tableColumn);
            $rules[$ruleIndex] = 'in:'.implode(',', $enumList);
            break;

        }
      }
      $this->validationRules[$tableColumn] = implode('|', $rules);
    }
    $this->defaultValidationInitialized = true;
  }
  public function getTableColumns() {
    return $this->getConnection()->getSchemaBuilder()->getColumnListing(str_plural($this->getTable()));
  }
  // public function getAttributes(){
  //   return $this->attributes;
  // }
  // public function getTableDetail(){
  //   return $this->getConnection()->getSchemaBuilder()->listTableDetails("inventory");
  // }
  // public function newModel($table, $attribute){
  //   $modelName = "App\\".str_replace(' ', '', ucwords(str_replace('_', ' ', str_singular($table))));
  //   return new $modelName($attribute);
  // }
  public static function getPossibleEnumValues($name){
    $instance = new static; // create an instance of the model to be able to get the table name
    $type = DB::select( DB::raw('SHOW COLUMNS FROM '.$instance->getTable().' WHERE Field = "'.$name.'"') )[0]->Type;
    preg_match('/^enum\((.*)\)$/', $type, $matches);
    $enum = array();
    foreach(explode(',', $matches[1]) as $value){
        $v = trim( $value, "'" );
        $enum[] = $v;
    }
    return $enum;
  }
  public function createEntry($entry){
    $entry = $this->systemGenerateValue($entry);

    // if($entry == null){
    //   echo "System Generate Value has no returned data!";
    //   exit();
    // }
    if($entry !== null){
        unset($entry['id']);
        unset($entry['created_at']);
        unset($entry['updated_at']);
        unset($entry['deleted_at']);

      foreach($entry as $entryColumn => $entryValue){
        $value = $entryValue;
        if($this->useSessionCompanyID && ($entryColumn == 'company_id' && config('payload.company_id') * 1 !== 1)){
          $value = $this->userSession('company_id');
        }else if($value == null){
          $value = isset($this->defaultValue[$entryColumn]) ? $this->defaultValue[$entryColumn] : $value;
        }
        $this->$entryColumn = $value;
      }
    }
    $this->save();
    return $this->id;
  }
  public function updateEntry($id, $entry, $foreignColumn = null, $foreignID = 0){
    $entry = $this->systemGenerateValue($entry);
    unset($entry['id']);
    unset($entry['created_at']);
    unset($entry['updated_at']);
    unset($entry['deleted_at']);
    $currentData = $this->where('id', $id)->get()->toArray();
    if(!count($currentData)){
      echo 'updating non existent entry' . $id;
      return null;
    }
    $withCompanyID = false;
    if(config('payload.company_id') * 1 !== 1){
      if(isset($entry['company_id'])){
        $currentData[0]['company_id'] = $this->userSession('company_id');
        unset($entry['company_id']);
      }
      $withCompanyID = isset($this->getTableColumns()['company_id']);
    }
    foreach($entry as $entryColumn => $entryValue){
      $value = $entryValue;
      if($entryValue == null && isset($this->defaultValue[$entryColumn])){
        $value = $this->defaultValue[$entryColumn];
      }
      if(($value == "\"\"" || $value == "''" || $value == "\'\'") && !is_numeric($value)){
        $value = "";

      }
      // if($entryColumn == 'link'){
      //   echo gettype($value);
      // }
      if($value !== null){
        $currentData[0][$entryColumn] = $value;
      }else{
        unset($currentData[0][$entryColumn]);
      }
    }
    if($foreignID * 1){
      $this->where($foreignColumn, $foreignID);
    }
    unset($currentData[0]['created_at']);
    unset($currentData[0]['updated_at']);
    unset($currentData[0]['deleted_at']);
    if($withCompanyID){
      return $this->where("id", $id)->where('company_id', $this->userSession('company_id'))->update($currentData[0]);
    }else{
      return $this->where("id", $id)->update($currentData[0]);
    }
  }
  public function deleteEntry($id = null){
    if($id){
      return $this->where("id", $id)->delete();
    }else{
      return $this->delete();
    }
  }
  public function userSession($key = "id"){
    if(config('payload')){
      $user = config('payload');

      if($key){
        return $user[$key];
      }else{
        return $user;
      }
    }else{
      return null;
    }
  }
}
