<?php

namespace Prettus\Repository\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;

/**
 * Class RequestCriteria
 * @package Prettus\Repository\Criteria
 * @author Anderson Andrade <contato@andersonandra.de>
 */
class RequestCriteria implements CriteriaInterface 
{
    /**
     * @var \Illuminate\Http\Request
     */
    protected $request;

    public function __construct(Request $request) 
    {
        $this->request = $request;
    }


    /**
     * Apply criteria in query repository
     *
     * @param Builder|Model $model
     * @param RepositoryInterface $repository
     *
     * @return mixed
     * @throws \Exception
     */
    public function apply($model, RepositoryInterface $repository) 
    {
        $fieldsSearchable = $repository->getFieldsSearchable();
        $search = $this->request->get(config('repository.criteria.params.search', 'search'), null);
        $searchFields = $this->request->get(config('repository.criteria.params.searchFields', 'searchFields'), null);
        $filter = $this->request->get(config('repository.criteria.params.filter', 'filter'), null);
        $orderBy = $this->request->get(config('repository.criteria.params.orderBy', 'orderBy'), null);
        $sortedBy = $this->request->get(config('repository.criteria.params.sortedBy', 'sortedBy'), 'asc');
        $with = $this->request->get(config('repository.criteria.params.with', 'with'), null);
        $withCount = $this->request->get(config('repository.criteria.params.withCount', 'withCount'), null);
        $searchJoin = $this->request->get(config('repository.criteria.params.searchJoin', 'searchJoin'), null);
        $sortedBy = !empty($sortedBy) ? $sortedBy : 'asc';

        if ($search && is_array($fieldsSearchable) && count($fieldsSearchable)) {

            $searchFields = is_array($searchFields) || is_null($searchFields) ? $searchFields : explode(';', $searchFields);
            $fields = $this->parserFieldsSearch($fieldsSearchable, $searchFields);
            $isFirstField = true;
            $searchData = $this->parserSearchData($search);
            $search = $this->parserSearchValue($search);
            $modelForceAndWhere = strtolower($searchJoin) === 'and';

            $model = $model->where(function ($query) use ($fields, $search, $searchData, $isFirstField, $modelForceAndWhere, $searchJoin) {
                /** @var Builder $query */

                foreach ($fields as $field => $condition) {

                    if (is_numeric($field)) {
                        $field = $condition;
                        $condition = "=";
                    }

                    $value = null;

                    $condition = trim(strtolower($condition));

                    if (isset($searchData[$field])) {
                        if (is_array($searchData[$field])) {
                            foreach ($searchData[$field] as $searchValue) {
                                $value[] = ($condition == "like" || $condition == "ilike") ? "%{$searchData[$field]}%" : $searchData[$field];
                            }
                        } else {
                            $value = ($condition == "like" || $condition == "ilike") ? "%{$searchData[$field]}%" : $searchData[$field];
                        }
                    } else {
                        if (!is_null($search) && !in_array($condition, ['in', 'between'])) {
                            $value = ($condition == "like" || $condition == "ilike") ? "%{$search}%" : $search;
                        }
                    }

                    $relation = null;
                    if (stripos($field, '.')) {
                        $explode = explode('.', $field);
                        $field = array_pop($explode);
                        $relation = implode('.', $explode);
                    }
                    if ($condition === 'in') {
                        $value = explode(',', $value);
                        if (trim($value[0]) === "" || $field == $value[0]) {
                            $value = null;
                        }
                    }
                    if ($condition === 'between') {
                        $value = explode(',', $value);
                        if (count($value) < 2) {
                            $value = null;
                        }
                    }
                    $modelTableName = $query->getModel()->getTable();
                    if ($isFirstField || $modelForceAndWhere) {
                        if (!is_null($value)) {
                            if (!is_null($relation)) {
                                try {
                                    $query->whereHas($relation, function ($query) use ($field, $condition, $value, $searchJoin) {

                                        if ($condition === 'in') {
                                            $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereIn' : 'whereIn';
                                            $query->$whereCondition($field, $value);
                                        } elseif ($condition === 'between') {
                                            $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereBetween' : 'whereBetween';
                                            $query->$whereCondition($field, $value);
                                        } else {
                                            if (is_array($value)) {
                                                //if we have an array we want to search by multiple values for the same field
                                                $query->where(function ($query) use ($field, $condition, $value, $searchJoin) {
                                                    foreach ($value as $val) {
                                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                                        $query->$whereCondition($field, $condition, $val);
                                                    }
                                                });
                                            } else {
                                                $query->where($field, $condition, $value);
                                            }
                                        }
                                    });
                                } catch (\Exception $e) {
                                    if ($condition === 'in') {
                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereIn' : 'whereIn';
                                        $query->$whereCondition($relation . '.' . $field, $value);
                                    } elseif ($condition === 'between') {
                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereBetween' : 'whereBetween';
                                        $query->$whereCondition($relation . '.' . $field, $value);
                                    } else {
                                        if (is_array($value)) {
                                            //if we have an array we want to search by multiple values for the same field
                                            $query->where(function ($query) use ($relation, $field, $condition, $value, $searchJoin) {
                                                foreach ($value as $val) {
                                                    $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                                    $query->$whereCondition($relation . '.' . $field, $condition, $val);
                                                }
                                            });
                                        } else {
                                            $query->where($relation . '.' . $field, $condition, $value);
                                        }
                                    }
                                }
                            }
                            else {
                                if ($condition === 'in') {
                                    $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereIn' : 'whereIn';
                                    $query->$whereCondition($modelTableName . '.' . $field, $value);
                                } elseif ($condition === 'between') {
                                    $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereBetween' : 'whereBetween';
                                    $query->$whereCondition($modelTableName . '.' . $field, $value);
                                } else {
                                    //$query->where($modelTableName.'.'.$field,$condition,$value);
                                    if (is_array($value)) {
                                        //if we have an array we want to search multiple values for the same field
                                        $query->where(function ($query) use ($modelTableName, $field, $condition, $value, $searchJoin) {
                                            foreach ($value as $val) {
                                                $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                                $query->$whereCondition($modelTableName . '.' . $field, $condition, $val);
                                            }
                                        });
                                    } else {
                                        $query->where($modelTableName . '.' . $field, $condition, $value);
                                    }
                                }
                            }
                            $isFirstField = false;
                        }
                    } else {
                        if (!is_null($value)) {
                            if (!is_null($relation)) {
                                try {
                                    $query->orWhereHas($relation, function ($query) use ($field, $condition, $value, $searchJoin) {

                                        if ($condition === 'in') {
                                            $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereIn' : 'whereIn';
                                            $query->$whereCondition($field, $value);
                                        } elseif ($condition === 'between') {
                                            $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereBetween' : 'whereBetween';
                                            $query->$whereCondition($field, $value);
                                        } else {
                                            if (is_array($value)) {
                                                //if we have an array we want to search multiple values for the same field
                                                $query->where(function ($query) use ($field, $condition, $value, $searchJoin) {
                                                    foreach ($value as $val) {
                                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                                        $query->$whereCondition($field, $condition, $val);
                                                    }
                                                });
                                            } else {
                                                $query->where($field, $condition, $value);
                                            }
                                        }
                                    });
                                } catch (\Exception $e) {
                                    if ($condition === 'in') {
                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereIn' : 'whereIn';
                                        $query->$whereCondition($relation . '.' . $field, $value);
                                    } elseif ($condition === 'between') {
                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereBetween' : 'whereBetween';
                                        $query->$whereCondition($relation . '.' . $field, $value);
                                    } else {
                                        if (is_array($value)) {
                                            //if we have an array we want to search by multiple values for the same field
                                            $query->where(function ($query) use ($relation, $field, $condition, $value, $searchJoin) {
                                                foreach ($value as $val) {
                                                    $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                                    $query->$whereCondition($relation . '.' . $field, $condition, $val);
                                                }
                                            });
                                        } else {
                                            $query->where($relation . '.' . $field, $condition, $value);
                                        }
                                    }
                                }
                            } else {
                                if ($condition === 'in') {
                                    $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereIn' : 'whereIn';
                                    $query->$whereCondition($modelTableName . '.' . $field, $value);
                                } elseif ($condition === 'between') {
                                    $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhereBetween' : 'whereBetween';
                                    $query->$whereCondition($modelTableName . '.' . $field, $value);
                                } else {
                                    if (is_array($value)) {
                                        foreach ($value as $val) {
                                            $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                            $query->$whereCondition($modelTableName . '.' . $field, $condition, $value);
                                        }
                                    } else {
                                        $whereCondition = (strtolower($searchJoin) === 'or') ? 'orWhere' : 'where';
                                        $query->$whereCondition($modelTableName . '.' . $field, $condition, $value);
                                    }
                                }
                            }
                        }
                    }
                }
            });
        }

        if (isset($orderBy) && !empty($orderBy)) {
            $orderBySplit = explode(';', $orderBy);
            if (count($orderBySplit) > 1) {
                $sortedBySplit = explode(';', $sortedBy);
                foreach ($orderBySplit as $orderBySplitItemKey => $orderBySplitItem) {
                    $sortedBy = isset($sortedBySplit[$orderBySplitItemKey]) ? $sortedBySplit[$orderBySplitItemKey] : $sortedBySplit[0];
                    $model = $this->parserFieldsOrderBy($model, $orderBySplitItem, $sortedBy);
                }
            } else {
                $model = $this->parserFieldsOrderBy($model, $orderBySplit[0], $sortedBy);
            }
        }

        if (isset($filter) && !empty($filter)) {
            if (is_string($filter)) {
                $filter = explode(';', $filter);
            }

            $model = $model->select($filter);
        }

        if ($with) {
            $with = explode(';', $with);
            $model = $model->with($with);
        }

        if ($withCount) {
            $withCount = explode(';', $withCount);
            $model = $model->withCount($withCount);
        }

        return $model;
    }

    /**
     * @param $model
     * @param $orderBy
     * @param $sortedBy
     * @return mixed
     */
    protected function parserFieldsOrderBy($model, $orderBy, $sortedBy) 
    {
        $split = explode('|', $orderBy);
        if (count($split) > 1) {
            /*
             * ex.
             * products|description -> join products on current_table.product_id = products.id order by description
             *
             * products:custom_id|products.description -> join products on current_table.custom_id = products.id order
             * by products.description (in case both tables have same column name)
             */
            $table = $model->getModel()->getTable();
            $sortTable = $split[0];
            $sortColumn = $split[1];

            $split = explode(':', $sortTable);
            $localKey = '.id';
            if (count($split) > 1) {
                $sortTable = $split[0];

                $commaExp = explode(',', $split[1]);
                $keyName = $table . '.' . $split[1];
                if (count($commaExp) > 1) {
                    $keyName = $table . '.' . $commaExp[0];
                    $localKey = '.' . $commaExp[1];
                }
            } else {
                /*
                 * If you do not define which column to use as a joining column on current table, it will
                 * use a singular of a join table appended with _id
                 *
                 * ex.
                 * products -> product_id
                 */
                $prefix = Str::singular($sortTable);
                $keyName = $table . '.' . $prefix . '_id';
            }

            $model = $model
                ->leftJoin($sortTable, $keyName, '=', $sortTable . $localKey)
                ->orderBy($sortColumn, $sortedBy)
                ->addSelect($table . '.*');
        } else {
            $model = $model->orderBy($orderBy, $sortedBy);
        }
        return $model;
    }

    /**
     * @param $search
     *
     * @return array
     */
    protected function parserSearchData($search) 
    {
        $searchData = [];

        if (stripos($search, ':')) {
            $fields = explode(';', $search);

            foreach ($fields as $row) {
                try {
//                    list($field, $value) = explode(':', $row);
                    $fieldAndValues = explode(':', $row);
                    //check if we have more than one (field search) value
                    //first item in array is field name string
                    if (count($fieldAndValues) > 2) {
                        $field = array_shift($fieldAndValues);
                        //what remains are multiple search values in array
                        $value = $fieldAndValues;
                    } else {
                        //if we only have field name and one search value just set them
                        list($field, $value) = $fieldAndValues;
                    }
                    try {
                        $json_value = json_decode($value);
                        if ($json_value !== null) {
                            $value = $json_value;
                        }
                    } catch (\Exception $exp) {

                    }
                    $searchData[$field] = $value;
                } catch (\Exception $e) {
                    //Surround offset error
                }
            }
        }

        return $searchData;
    }

    /**
     * @param $search
     *
     * @return null
     */
    protected function parserSearchValue($search) 
    {

        if (stripos($search, ';') || stripos($search, ':')) {
            $values = explode(';', $search);
            foreach ($values as $value) {
                $s = explode(':', $value);
                if (count($s) == 1) {
                    return $s[0];
                }
            }

            return null;
        }

        return $search;
    }


    protected function parserFieldsSearch(array $fields = [], array $searchFields = null) 
    {
        if (!is_null($searchFields) && count($searchFields)) {
            $acceptedConditions = config('repository.criteria.acceptedConditions', [
                '=',
                'like',
            ]);
            $originalFields = $fields;
            $fields = [];

            foreach ($searchFields as $index => $field) {
                $field_parts = explode(':', $field);
                $temporaryIndex = array_search($field_parts[0], $originalFields);

                if (count($field_parts) == 2) {
                    if (in_array($field_parts[1], $acceptedConditions)) {
                        unset($originalFields[$temporaryIndex]);
                        $field = $field_parts[0];
                        $condition = $field_parts[1];
                        $originalFields[$field] = $condition;
                        $searchFields[$index] = $field;
                    }
                }
            }

            foreach ($originalFields as $field => $condition) {
                if (is_numeric($field)) {
                    $field = $condition;
                    $condition = "=";
                }
                if (in_array($field, $searchFields)) {
                    $fields[$field] = $condition;
                }
            }

            if (count($fields) == 0) {
                throw new \Exception(trans('repository::criteria.fields_not_accepted', ['field' => implode(',', $searchFields)]));
            }

        }

        return $fields;
    }
}

