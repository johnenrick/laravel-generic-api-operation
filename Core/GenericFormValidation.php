<?php

namespace App\Http\Controllers\Core;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GenericFormValidation extends Controller
{
  private $validationRule = [];
  private $tableStructure;
  private $apiOperation = 'update';
  private $uniqueValidationRule = [];
  public $validationErrors = null;
  public $additionalRule = [];
  public function __construct($tableStructure, $apiOperation = 'update'){
    $this->tableStructure = $tableStructure;
    $this->apiOperation = $apiOperation;
    $this->generateValidationRule();
  }
  public function extractValidationRule($tableStructure, $parentTableName = null, $foreignTableName = null){
    //TODO make this function recursive
    $singularParent = $parentTableName ? str_singular($parentTableName) : null;
    foreach($tableStructure['columns'] as $column => $columnSetting){
      if($this->apiOperation == 'create' && ((strpos( $column, "_id" ) && str_replace('_id', '', $column) == str_singular($this->tableStructure['table_name'])) || $column == 'id' )){ // exclude primary and foreign key in create operation

      }else{
        $prefix = '';
        if($foreignTableName){
          $prefix = $foreignTableName.".".(($foreignTableName == str_plural($foreignTableName)) ? '*.' :'');
        }

        $rules = isset($columnSetting['validation'])?  explode("|", $columnSetting['validation']) : [];
        $finalizedRule = [];
        foreach($rules as $rule){
          $ruleNameParameterSegment = explode(":", $rule);
          switch($ruleNameParameterSegment[0]){
            case "unique":
              if(strpos($ruleNameParameterSegment[1],",except,id")){
                if(!isset($this->uniqueValidationRule[$prefix . $column])){
                  $this->uniqueValidationRule[$prefix . $column] = [];
                }
                $this->uniqueValidationRule[$prefix . $column] = str_replace(',except,id', '', $ruleNameParameterSegment[1]);
              }else{
                $finalizedRule[] = $rule;
              }

              break;
            case "required":
              if($foreignTableName && $tableStructure['validation_required']){
                if($this->apiOperation == "create"){
                  $finalizedRule[] = $rule;
                }else if($this->apiOperation == "update"){
                  if($foreignTableName && $foreignTableName == str_plural($foreignTableName) && $column != 'id' && $singularParent.'_id' != $column){
                    // $finalizedRule[] = "required_without:".$foreignTableName.$prefix."id";
                    $finalizedRule[] = "required_without:".$prefix."id";
                  }
                }
              }else{
                if($this->apiOperation == "create"){
                  $finalizedRule[] = $rule;
                }else if($this->apiOperation == "update"){
                  if($foreignTableName && $foreignTableName == str_plural($foreignTableName) && $column != 'id' && $singularParent.'_id' != $column){
                    $finalizedRule[] = "required_without:$foreignTableName.*.id";
                  }
                }else{
                  $finalizedRule[] = 'min:1';
                }
              }
              break;
            case "required_with":
              if($foreignTableName){
                $modifiedRule = $ruleNameParameterSegment[0].":";
                $parameters = explode(",", $ruleNameParameterSegment[1]);
                foreach($parameters as $index => $parameter){
                  $parameters[$index] = str_replace('*.', '*',$prefix).$parameter;
                }
                $finalizedRule[] = $modifiedRule.implode(",", $parameters);
              }else{
                $finalizedRule[] = $rule;
              }
              break;
            default:
              if($rule != ''){
                $finalizedRule[] = $rule;
              }
          }
        }
        if($parentTableName){
          // printR($finalizedRule, $prefix . $column);
        }
        $this->validationRule[$prefix . $column] = $finalizedRule;
      }
    }
    if(count($tableStructure['foreign_tables'])){
      foreach($tableStructure['foreign_tables'] as $foreignTable => $foreignTableStructure){
        if($foreignTableStructure['validation_required']){
          $this->extractValidationRule($foreignTableStructure, $tableStructure['table_name'], $foreignTable);
        }
      }

    }
  }
  public function initializeRule(){

  }
  public function generateValidationRule(){
    $this->extractValidationRule($this->tableStructure);
    return $this->validationRule;
  }
  public function isValid($data){
    if(count($this->uniqueValidationRule)){
      foreach($this->uniqueValidationRule as $field => $rule){
        if(!isset($this->validationRule[$field])){
          $this->validationRule[$field] = [];
        }
        if($this->apiOperation == "create"){
          $this->validationRule[$field][] = "unique:".$rule;
        }else{
          $data['id'] = isset($data['id'])? $data['id'] : null;
          $this->validationRule[$field][] = "unique:".$rule.','.$data['id'];
        }
      }
    }
    if($this->apiOperation == "update"){
      $this->validationRule['id'] = "required";
    }
    // printR($this->validationRule);
    $validator = Validator::make($data, array_merge($this->validationRule, $this->additionalRule), $this->getCustomValidationMessages());
    if($validator->fails()){
      // TODO reformat validation message.
      $this->validationErrors = $validator->errors()->toArray();
      return false;
    }else{
      return true;
    }
  }
  public function getCustomValidationMessages(){
    return [
      'accepted'             => 'This must be accepted.',
      'active_url'           => 'The :attribute is not a valid URL.',
      'after'                => 'This must be a date after :date.',
      'after_or_equal'       => 'This must be a date after or equal to :date.',
      'alpha'                => 'This only contain letters.',
      'alpha_dash'           => 'This only contain letters, numbers, dashes and underscores.',
      'alpha_num'            => 'This only contain letters and numbers.',
      'array'                => 'This must be an array.',
      'before'               => 'This must be a date before :date.',
      'before_or_equal'      => 'This must be a date before or equal to :date.',
      'between'              => [
          'numeric' => 'This must be between :min and :max.',
          'file'    => 'This must be between :min and :max kilobytes.',
          'string'  => 'This must be between :min and :max characters.',
          'array'   => 'This must have between :min and :max items.',
      ],
      'boolean'              => 'This must be true or false.',
      'confirmed'            => 'The :attribute confirmation does not match.',
      'date'                 => 'The :attribute is not a valid date.',
      'date_format'          => 'The :attribute does not match the format :format.',
      'different'            => 'This and :other must be different.',
      'digits'               => 'This must be :digits digits.',
      'digits_between'       => 'This must be between :min and :max digits.',
      'dimensions'           => 'It has invalid image dimensions.',
      'distinct'             => 'This has a duplicate value.',
      'email'                => 'This must be a valid email address.',
      'exists'               => 'The selected :attribute is invalid.',
      'file'                 => 'This must be a file.',
      'filled'               => 'This must have a value.',
      'gt'                   => [
          'numeric' => 'This must be greater than :value.',
          'file'    => 'This must be greater than :value kilobytes.',
          'string'  => 'This must be greater than :value characters.',
          'array'   => 'This must have more than :value items.',
      ],
      'gte'                  => [
          'numeric' => 'This must be greater than or equal :value.',
          'file'    => 'This must be greater than or equal :value kilobytes.',
          'string'  => 'This must be greater than or equal :value characters.',
          'array'   => 'This must have :value items or more.',
      ],
      'image'                => 'This must be an image.',
      'in'                   => 'The selected :attribute is invalid.',
      'in_array'             => 'This does not exist in :other.',
      'integer'              => 'This must be an integer.',
      'ip'                   => 'This must be a valid IP address.',
      'ipv4'                 => 'This must be a valid IPv4 address.',
      'ipv6'                 => 'This must be a valid IPv6 address.',
      'json'                 => 'This must be a valid JSON string.',
      'lt'                   => [
          'numeric' => 'This must be less than :value.',
          'file'    => 'This must be less than :value kilobytes.',
          'string'  => 'This must be less than :value characters.',
          'array'   => 'This must have less than :value items.',
      ],
      'lte'                  => [
          'numeric' => 'This must be less than or equal :value.',
          'file'    => 'This must be less than or equal :value kilobytes.',
          'string'  => 'This must be less than or equal :value characters.',
          'array'   => 'This must not have more than :value items.',
      ],
      'max'                  => [
          'numeric' => 'This must not be greater than :max.',
          'file'    => 'This must not be greater than :max kilobytes.',
          'string'  => 'This must not be greater than :max characters.',
          'array'   => 'This must not have more than :max items.',
      ],
      'mimes'                => 'This must be a file of type: :values.',
      'mimetypes'            => 'This must be a file of type: :values.',
      'min'                  => [
          'numeric' => 'This must be at least :min.',
          'file'    => 'This must be at least :min kilobytes.',
          'string'  => 'This must be at least :min characters.',
          'array'   => 'This must have at least :min items.',
      ],
      'not_in'               => 'The selected :attribute is invalid.',
      'not_regex'            => 'Format is invalid.',
      'numeric'              => 'This must be a number.',
      'present'              => 'This must be present.',
      'regex'                => 'Format is invalid.',
      'required'             => 'This is required.',
      'required_if'          => 'This is required when :other is :value.',
      'required_unless'      => 'This is required unless :other is in :values.',
      'required_with'        => 'This is required when :values is present.',
      'required_with_all'    => 'This is required when :values is present.',
      'required_without'     => 'This is required when :values is not present.',
      'required_without_all' => 'This is required when none of :values are present.',
      'same'                 => 'This and :other must match.',
      'size'                 => [
          'numeric' => 'This must be :size.',
          'file'    => 'This must be :size kilobytes.',
          'string'  => 'This must be :size characters.',
          'array'   => 'This must contain :size items.',
      ],
      'string'               => 'This must be a string.',
      'timezone'             => 'This must be a valid zone.',
      'unique'               => 'It has already been taken.',
      'uploaded'             => 'It failed to upload.',
      'url'                  => 'Format is invalid.'
      ];
  }
}
