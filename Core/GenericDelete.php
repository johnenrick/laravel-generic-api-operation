<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class GenericDelete extends Controller
{
  protected $tableStructure = null;
  protected $model = null;
  public function __construct($tableStructure, $model){
    $this->tableStructure = $tableStructure;
    $this->model = $model;
  }
  public function delete($id = null, $condition = null){
    $result = $this->deleteEntryRecursively($id, $condition, $this->tableStructure);
    return $result;
  }
  public function deleteEntryRecursively($id = null, $condition = null, $tableStructure){
    $result = [];
    $entryToSave = $this->entryAllowedData($condition, $tableStructure);
    $condition ? $this->addConditionStatement($condition, $tableStructure) : null;
    $result['deleted'] = $this->model->deleteEntry($id);
    if($id){
      $result['id'] = $id;
    }
    // TODO option to delete children
    return $result;
  }
  public function addConditionStatement($requestQueryCondition, $tableStructure){
    foreach($requestQueryCondition as $condition){
      $condition['clause'] = isset($condition['clause']) ? $condition['clause'] : '=';
      switch($condition['clause']){
        default:
        // echo get_class($this->model);
        $this->model = $this->model->where($condition['column'], $condition['clause'], $condition['value']);
        // echo get_class($this->model);
      }
    }
  }
  public function entryAllowedData($entry, $tableStructure){
    $entryToSave = [];
    foreach($tableStructure['columns'] as $column => $value){
      $entryToSave[$column] = isset($entry[$column]) ? $entry[$column] : null;
    }
    return $entryToSave;
  }
}
