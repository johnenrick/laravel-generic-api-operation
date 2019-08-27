<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class GenericUpdate extends Controller
{
  protected $tableStructure = null;
  protected $model = null;
  public function __construct($tableStructure, $model){
    $this->tableStructure = $tableStructure;
    $this->model = $model;
  }
  public function update($entry){
    $result = $this->updateEntryRecursively($entry, $this->model,$this->tableStructure);
    return $result;
  }
  public function updateEntryRecursively($entry, $model, $tableStructure, $foreignColumn = null, $foreignID = null){
    $result = ['id' => null];
    $entryToSave = $this->entryAllowedData($entry, $tableStructure);
    $deleted = isset($entry['deleted']) ? $entry['deleted'] : false;
    if(isset($entry['id']) && $entry['id'] && !$deleted){
      $model->updateEntry($entryToSave['id'], $entryToSave, $foreignColumn, $foreignID);
      $result['id'] = $entryToSave['id'];
    }else if($deleted){ // delete
      $result['id'] = $entryToSave['id'];
      $result['deleted'] = $model->deleteEntry($entry['id'], $foreignColumn, $foreignID);
    }else{
      $result['id'] = (new $tableStructure['model_name']())->createEntry($entryToSave);
    }
    $id = $result['id'];
    foreach($tableStructure['foreign_tables'] as $foreignTableName => $foreignTable){ // loop the foreign tables
      $foreignColumnName = str_singular($tableStructure['table_name']) .'_id';
      if(isset($foreignTable['columns'][$foreignColumnName]) && isset($entry[$foreignTableName])){ // insert as child && entry has foreign table
        $foreignTableModel = new $foreignTable['model_name']();
        if($foreignTable['multiple']){
          foreach($entry[$foreignTableName] as $x => $value){
            $entry[$foreignTableName][$x][$foreignColumnName] = $id;
            $result[$foreignTableName][$x] = $this->updateEntryRecursively($entry[$foreignTableName][$x], $foreignTableModel, $foreignTable, str_singular($foreignTableName), $id);
          }
        }else{
          $entry[$foreignTableName][$foreignColumnName] = $id;
          $result[$foreignTableName] = $this->updateEntryRecursively($entry[$foreignTableName], $foreignTableModel, $foreignTable);
        }
      }
    }
    return $result;
  }
  public function entryAllowedData($entry, $tableStructure){
    $entryToSave = [];
    foreach($tableStructure['columns'] as $column => $value){
      if(!isset($value['formula'])){
        isset2($column, $entry) ? $entryToSave[$column] = $entry[$column] : null;
      }
    }
    return $entryToSave;
  }
}
