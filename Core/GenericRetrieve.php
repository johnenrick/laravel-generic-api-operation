<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class GenericRetrieve extends Controller
{
    /***
      [
        select => [
            tae =>
          ],
          [
            table
          ]
        ]
    */
    private $tableStructure;
    private $model;
    private $requestQuery;
    private $requesPostQuery = ['select' => []];
    private $hasLeftJoinedTable;
    public $totalResult = null;
    public $resultArray = null;
    public $customQueryModel = null;
    public $isleftJoined = false;
    public function __construct($tableStructure, $model, $requestQuery, $customQueryModel = null){
      $this->tableStructure = $tableStructure;
      $this->model = $model;
      $this->requestQuery = $requestQuery;
      $this->removeNotAllowedQuery();
      $this->customQueryModel = $customQueryModel;

    }
    public function removeNotAllowedQuery(){
      $this->requestQuery['select'] = $this->removeUnwantedSelectForeignTable($this->requestQuery['select'], $this->tableStructure, null, $this->requesPostQuery);
    }
    public function executeQuery(){
      $leftJoinedTable = [];
      if($this->customQueryModel){
        $customQueryModel = $this->customQueryModel;
        $this->model = $customQueryModel($this->model, $leftJoinedTable);
      }
      $this->model = $this->addQueryStatements($this->model, $this->requestQuery, $this->tableStructure, $leftJoinedTable);
      $this->resultArray = $this->model->get()->toArray();
      if(isset($this->requestQuery['id']) && $this->requestQuery['id']){
        if(count($this->resultArray)){
          return $this->resultArray[0];
        }else{
          return null;
        }
      }
      $this->resultArray = collect($this->resultArray)->unique('id')->values()->all();
      $this->postQuery();
      return $this->resultArray;
    }
    public function postQuery(){
      foreach($this->resultArray as $resultKey => $result){
        foreach($this->requesPostQuery as $foreingTableKey => $foreignTable){
          if(isset($foreignTable['limit'])){
            $this->resultArray[$resultKey][$foreingTableKey] = array_slice($this->resultArray[$resultKey][$foreingTableKey], 0, $foreignTable['limit']);
          }
        }
      }
    }
    public function addQueryStatements($queryModel, $requestQuery, $tableStructure, $leftJoinedTable = []){
      $select = [];
      $with = [];
      // $queryModel->addSelect('id');
      foreach($requestQuery['select'] as $selectIndex => $select){ // add select statement
        if($select == null){ //column not foreign table
          $slectedColumn;
          if(isset($tableStructure['columns'][$selectIndex]['formula'])){
            $slectedColumn = $tableStructure['columns'][$selectIndex]['formula'];
          }else{
            $slectedColumn = $tableStructure['true_table'].".".$selectIndex;
          }

          $queryModel = $queryModel->addSelect(DB::raw("$slectedColumn as ".$selectIndex));
        }else{
          $with[$selectIndex] = function($queryModel2) use($select, $selectIndex, $tableStructure){
            $this->addQueryStatements($queryModel2, $select, $tableStructure['foreign_tables'][$selectIndex], []);
          };
        }
      }
      $hasID = isset($requestQuery['id']) && $requestQuery['id'];
      if($hasID){
        $queryModel = $queryModel->where($tableStructure['table_name'].'.id', $requestQuery['id']);
      }else{
        isset($requestQuery['condition']) ? $queryModel = $this->addConditionStatement($queryModel, $requestQuery['condition'], $leftJoinedTable, $tableStructure) : null;
      }
      isset($requestQuery['sort']) ? $queryModel = $this->addSortStatement($queryModel, $requestQuery['sort'], $leftJoinedTable, $tableStructure) : null;
      if($tableStructure['table_name'] === $this->tableStructure['table_name']){
        if(isset($requestQuery['limit']) && isset2('offset', $requestQuery)){ // get the total page first
          $queryClone = clone $queryModel;
          $this->totalResult =  $queryClone->count();
        }
        isset($requestQuery['limit']) ? $queryModel = $queryModel->limit($requestQuery['limit']) : null;
        (isset($requestQuery['offset']) && $requestQuery['offset'] >= 0) ? $queryModel = $queryModel->offset($requestQuery['offset']) : null;
      }else {
        // isset($requestQuery['limit']) ? $queryModel = $queryModel->take($requestQuery['limit']) : null;
      }
      // $queryModel = $queryModel->groupBy('id');
      $this->hasLeftJoinedTable = count($leftJoinedTable) ? true : false;
      return $queryModel->with($with);
    }
    public function addConditionStatement($queryModel, $requestQueryCondition, &$leftJoinedTable, $tableStructure){
      foreach($requestQueryCondition as $condition){
        $column = $condition['column'];

        $queryModel = $this->addLeftJoin($queryModel, $leftJoinedTable, $column, $tableStructure); // the column is passed by address because the function will change its value
        $condition['clause'] = isset($condition['clause']) ? $condition['clause'] : '=';
        switch($condition['clause']){
          case 'not_in':
            $queryModel = $queryModel->whereNotIn(DB::raw($column), $condition['value']);
            break;
          case 'in':
            $queryModel = $queryModel->whereIn(DB::raw($column), $condition['value']);
            break;
          default:
            $queryModel = $queryModel->where(DB::raw($column), $condition['clause'], $condition['value']);
        }
      }
      return $queryModel;
    }
    public function addSortStatement($queryModel, $requestQuerySort, &$leftJoinedTable, $tableStructure){
      foreach($requestQuerySort as $sort){
        $column = $sort['column'];
        $queryModel = $this->addLeftJoin($queryModel, $leftJoinedTable, $column, $tableStructure); // the column is passed by address because the function will change the its value
        $explodedColumn = explode(".", $column);
        $rawColumn = $column;
        if(count($explodedColumn) > 1){
          if(isset($this->tableStructure['foreign_tables'][$explodedColumn[0]]['columns'][$explodedColumn[1]]['formula'])){
            $rawColumn = $this->tableStructure['foreign_tables'][$explodedColumn[0]]['columns'][$explodedColumn[1]]['formula'];
            // $queryModel->addSelect(DB::raw($rawColumn. ' AS `'. $column.'`'));
          }
        }
        $queryModel = $queryModel->orderBy(DB::raw($rawColumn), $sort['order']);
      }
      return $queryModel;
    }
    /**
    Addd a left join statement on the query

    */
    public function addLeftJoin($queryModel, &$leftJoinedTable, &$column, $tableStructure){
      $columnSplitted = explode(".", $column);
      if(count($columnSplitted) >= 2){ // table.column
        $table = $columnSplitted[0];
        $tablePlural = str_plural($table);
        $currentColumn = $columnSplitted[1];
        if(!in_array($tablePlural, $leftJoinedTable)){
          $leftJoinedTable[] = $tablePlural;
          $mainTable = $tableStructure['table_name'];
          if(isset($tableStructure['foreign_tables'][$table])){
            if($tableStructure['foreign_tables'][$table]['is_child']){
              $queryModel = $queryModel->join($tablePlural, function($join) use ($mainTable, $tablePlural){
                $join->on(str_plural($mainTable).".id", '=', $tablePlural.".".str_singular($mainTable)."_id");
              });
            }else{

              $queryModel = $queryModel->join($tablePlural, $tablePlural.".id", "=", str_plural($mainTable).".".str_singular($table)."_id");
            }
          }
        }
        if(isset($tableStructure['foreign_tables'][$table]['columns'][$currentColumn]['formula'])){
          $column = $tableStructure['foreign_tables'][$table]['columns'][$currentColumn]['formula'];
        }else{
          $column = $tablePlural.".".$currentColumn;
        }


      }else{
        if(isset($tableStructure['columns'][$column]['formula'])){
          $column = $tableStructure['columns'][$column]['formula'];
        }
          $column = str_plural($tableStructure['table_name']).".".$column;

      }
      return $queryModel;
    }
    public function removeUnwantedSelectForeignTable($requestQuerySelect, $tableStructure, $parentTable = null, &$requestPostQuery){
      $cleanRequestQuery = [];
      foreach($requestQuerySelect as $selectIndex => $select){
        if(is_numeric($selectIndex) && isset($tableStructure['columns'][$select])){ // if column
          $cleanRequestQuery[$select] = null; // transform for numeric index to column index with null value
        }else if(isset($tableStructure['foreign_tables'][$selectIndex]) && isset($select['select'])){ // if with
          $parent = $tableStructure['true_table'];
          if(!($tableStructure['foreign_tables'][$selectIndex]['is_child'])){
            $cleanRequestQuery[str_singular($selectIndex)."_id"] = null;
            $parent = null;
          }
          $requestPostQuery[$selectIndex] = ["select" => []];
          $cleanRequestQuery[$selectIndex]['select'] = $this->removeUnwantedSelectForeignTable($select['select'], $tableStructure['foreign_tables'][$selectIndex],  $parent, $requestPostQuery[$selectIndex]);
          isset($select['condition']) ? $cleanRequestQuery[$selectIndex]['condition'] = $select['condition']: null;
          isset($select['limit']) ? $cleanRequestQuery[$selectIndex]['limit'] = $select['limit']: null;
          isset($select['sort']) ? $cleanRequestQuery[$selectIndex]['sort'] = $select['sort']: null;
          if(isset($select['limit'])){
            $requestPostQuery[$selectIndex]['limit'] = $select['limit'];
          }
        }
      }
      $cleanRequestQuery['id'] = null;
      if($parentTable){
        $cleanRequestQuery[str_singular($parentTable)."_id"] = null;
      }else{

      }
      return $cleanRequestQuery;
    }
}
