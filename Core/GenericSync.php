<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App;

class GenericSync extends Controller
{
  protected $tableStructure = null;
  protected $model = null;
  public function __construct($tableStructure, $model){
    $this->tableStructure = $tableStructure;
    $this->model = $model;
  }
  public function sync($entries, $overrideValues = []){ // table structure refers to the current table structure
    $forCreateEntries = []; // Entries to be created
    $forCreateIndexLocalIdLookUp = array(); // look up to determine the local id base on index
    $forUpdateEntries = []; // Entries to be updated or deleted
    $tableStructureColumns = $this->tableStructure['columns'];
    foreach($entries as $entry){
      foreach($overrideValues as $column => $value){
        $entry[$column] = $value;
      }
      foreach($entry as $column => $value){
        if($column != 'db_id' && !isset($tableStructureColumns[$column])){
          unset($entry[$column]);
        }
      }
      
      if(isset($entry['db_id']) && $entry['db_id']){ // if db_id exists, it means it has already been synched
        $forUpdateEntries[] = $entry;
      }else{
        $forCreateIndexLocalIdLookUp[count($forCreateEntries)] = $entry['id'];
        unset($entry['id']);
        unset($entry['db_id']);
        $forCreateEntries[] = $entry;
      }
    }
    $createdEntriesIdList = $this->batchCreate($forCreateEntries);
    $createdEntriesResult = []; // [id, local_id]
    foreach($createdEntriesIdList as $index => $id){
      $createdEntriesResult[] = [
        "id" => $id,
        "local_id" => $forCreateIndexLocalIdLookUp[$index]
      ];
    }
    return [
      "created" => $createdEntriesResult,
      "updated" => $this->batchUpdate($forUpdateEntries)
    ];
  }
  private function batchCreate($entries){
    if(!count($entries)){
      return [];
    }
    $model = clone $this->model;
    $result = $model->insert($entries);
    if($result){
      $ids = ($model->orderBy('id', 'desc')->take(count($entries))->pluck('id'))->toArray();
      return array_reverse($ids);
    }else{
      return [];
    }
  }
  private function batchUpdate($entries){
    if(!count($entries)){
      return [];
    }
    $result = []; // [local_id:, error => [code, message] ], result can be true, or an error message
    foreach($entries as $entry){
      $dbId = $entry['db_id'];
      $localId = $entry['id'];
      $resultEntry = [
        "local_id" => $localId,
        "error" => null
      ];
      unset($entry['db_id']);
      unset($entry['id']);
      unset($entry['created_at']);
      $model = new $this->model;
      $existingEntry = $model->find($dbId);
      if(!$existingEntry){
        $resultEntry['error'] = [2, 'Entry does not exist or maybe already deleted'];
      }else if(strtotime($existingEntry['updated_at']) < strtotime($entry['updated_at'])){
        $existingEntry = $existingEntry->toArray();
        $model = $model->find($dbId);
        foreach($entry as $column => $value){ // assign value to each columns
          $model->$column = $value;
        }
        if(!$model->save()){
          $resultEntry['error'] = [3, "Database Update Failed"];
        }
      }else{
        $resultEntry['error'] = [1, $existingEntry, 'Newer data is found in the database'];
      }
      $result[] = $resultEntry;
    }
    return $result;
  }
}