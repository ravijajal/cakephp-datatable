<?php

namespace Jajal\Datatable;

use Cake\ORM\Query;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Cake\Validation\Validator;
use \Exception as PhpCoreException;

class Datatable {

    public $config;
    protected $request;

    public function __construct($config, array $request) {
        if ($this->validationConfig($config)) {
            $this->config = $config;
        }
        $this->request = $request;
    }

    public function columns() {
        return (array) $this->request['columns'];
    }

    public function order() {
        return (array) $this->request['order'];
    }

    public function limit() {
        return $this->request['length'];
    }

    public function offset() {
        return $this->request['start'];
    }

    public function config($configName = '') {
        if ($configName == '') {
            return $this->config;
        }
        if (isset($this->config[$configName])) {
            return $this->config[$configName];
        }
        return array();
    }

    public function getDatatableJson() {
        return json_encode(array_values($this->config('datatable')));
    }

    public function getDatatableSearchJson() {
        return json_encode(array_values($this->config('datatable-search')));
    }

    public function generate() {
        $html = '<table ' . $this->getTableHtmlOptions() . '>'
                . '<thead>'
                . '<tr>';
        foreach ($this->config('view') as $column) {
            $html .= '<th ' . $this->getHtmlOptions($this->config('columnHtmlOptions')) . '>' . $column['label'] . '</th>';
        }
        $html .= '</tr>'
                . '<tr>';
        $index = 0;
        foreach ($this->config('view') as $name => $column) {
            $searchColumns = $this->config('datatable-search');
            if (isset($column['search']) && $column['search']) {
                $htmlOptions = isset($column['htmlOptions']) ? $this->getHtmlOptions($column['htmlOptions']) : '';
                $searchValue = '';
                if ($searchColumns[$name] != null && is_array($searchColumns[$name]) && $searchColumns[$name]['search'] != '') {
                    $searchValue = $searchColumns[$name]['search'];
                }
                if ($column['searchType'] == 'text') {
                    $valueHtml = '';
                    if ($searchValue != "") {
                        $valueHtml = ' value = "' . $searchValue . '" ';
                    }
                    $html .= '<td><input type="text" ' . $htmlOptions . ' data-index="' . $index . '" ' . $valueHtml . '></td>';
                } else if ($column['searchType'] == 'select') {
                    $html .= '<td><select ' . $htmlOptions . ' data-index="' . $index . '">';
                    if (isset($column['searchEmpty'])) {
                        $html .= '<option value="">' . $column['searchEmpty'] . '</option>';
                    }
                    foreach ($column['searchOptions'] as $value => $label) {
                        $seletecdHtml = '';
                        if ((isset($column['searchSelected']) && $column['searchSelected'] == $value) || ($searchValue != "" && $searchValue == $value)) {
                            $seletecdHtml = ' selected = "selected" ';
                        }
                        $html .= '<option value="' . $value . '" ' . $seletecdHtml . '>' . $label . '</option>';
                    }
                    $html .= '</select></td>';
                }
            } else {
                $html .= '<td></td>';
            }
            $index++;
        }
        $html .= '</tr>'
                . '</thead>'
                . '<tbody>'
                . '</tbody>'
                . '</table>';
        return $html;
    }

    public function getTableHtmlOptions() {
        return $this->getHtmlOptions($this->config('htmlOptions'));
    }

    public function getHtmlOptions($htmlOptions) {
        $optionsStr = '';
        foreach ($htmlOptions as $name => $value) {
            $optionsStr .= ' ' . $name . '="' . $value . '" ';
        }
        return $optionsStr;
    }

    public function fields() {
        $dbColumns = [];
        foreach ($this->config('database') as $dbColumn) {
            $dbColumns[] = $dbColumn['name'];
        }
        return $dbColumns;
    }

    public function data(Query $query) {

        $this->validateRequestData();
        $columnsArr = $this->columns();
        $orderArr = $this->order();
        $fieldArr = $this->fields();
        $query->select($fieldArr);
        $databaseConfig = $this->config('database');
        foreach ($orderArr as $order) {
            $this->validateOrder($order);
            $columnName = $columnsArr[$order['column']]['data'];
            if (isset($databaseConfig[$columnName])) {
                $query->order([$databaseConfig[$columnName]['name'] => $order['dir']]);
            }
        }
        $whereArr = [];
        foreach ($columnsArr as $column) {
            if (isset($databaseConfig[$column['data']]) && isset($databaseConfig[$column['data']]['search']) && $databaseConfig[$column['data']]['search'] && $column['search']['value'] != '') {
                $this->validateColumn($column);
                if ($databaseConfig[$column['data']]['searchType'] == 'text') {
                    if (is_numeric($column['search']['value'])) {
                        $whereArr[] = [$databaseConfig[$column['data']]['name'] => $column['search']['value']];
                    } else {
                        $whereArr[] = [function ($exp) use($databaseConfig, $column) {
                                return $exp->like($databaseConfig[$column['data']]['name'], '%' . $column['search']['value'] . '%');
                            }];
                    }
                } else if ($databaseConfig[$column['data']]['searchType'] == 'method') {
                    $query = call_user_func_array($databaseConfig[$column['data']]['method'], [$query, $column['search']['value']]);
                }
            }
        }
        $query->where($whereArr);
        $recordsTotal = $query->count();
        if ($this->limit() != '') {
            $query->limit($this->limit());
        }
        if ($this->offset() != '') {
            $query->offset($this->offset());
        }
        $data = $query->toArray();

        $result = [];
        foreach ($data as $key => $row) {
            $result[] = $this->processRow($row);
        }
        return [
            'data' => $result,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
        ];
    }

    public function processRow($row) {
        $return = [];
        foreach ($this->config('columns') as $column) {
            $return[$column] = $row[$column];
        }
        return $return;
    }

    public function validationConfig($config) {
        $validator = Validation::createValidator();
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        // Validate values
        $constraint = new Collection([
            'columns' => new Required([
                new NotBlank(),
                new Type('array'),
                new All([
                    new Type('string')
                        ]),
                    ]),
            'datatable' => new Required([
                new NotBlank(),
                new Type('array'),
                new All([
                    new Collection([
                        'data' => new Required([
                            new NotBlank(),
                            new Type('string'),
                                ]),
                        'name' => new Optional([
                            new NotBlank(),
                            new Type('string'),
                                ]),
                        'searchable' => new Optional([
                            new Type('boolean'),
                                ]),
                        'sortable' => new Optional([
                            new Type('boolean'),
                                ]),
                        'visible' => new Optional([
                            new Type('boolean'),
                                ]),
                        'width' => new Optional([
                            new Type('string'),
                                ]),
                        'height' => new Optional([
                            new Type('string'),
                                ]),
                        'className' => new Optional([
                            new Type('string'),
                                ])
                            ])
                        ]),
                    ]),
            'datatable-search' => new Required([
                new NotBlank(),
                new Type('array'),
                new All([
                    new Collection([
                        'search' => new Required([
                            new NotBlank(),
                            new Type('string'),
                                ])
                            ]),
                        ]),
                    ]),
            'view' => new Required([
                new NotBlank(),
                new Type('array'),
                new All([
                    new Collection([
                        'label' => new Required([
                            new NotBlank(),
                            new Type('string'),
                                ]),
                        'search' => new Optional([
                            new Type('boolean'),
                                ]),
                        'searchType' => new Optional([
                            new Choice(['text', 'select']),
                                ]),
                        'searchEmpty' => new Optional([
                            new Type('string'),
                                ]),
                        'searchOptions' => new Optional([
                            new Type('array'),
                                ]),
                        'searchSelected' => new Optional([
                            new Optional(),
                            new Type('string'),
                                ]),
                        'htmlOptions' => new Optional([
                            new NotBlank(),
                            new Type('array'),
                                ]),
                            ])
                        ]),
                    ]),
            'database' => new Required([
                new NotBlank(),
                new Type('array'),
                new All([
                    new Collection([
                        'name' => new Required([
                            new NotBlank(),
                            new Type('string'),
                                ]),
                        'search' => new Optional([
                            new Type('boolean'),
                                ]),
                        'searchType' => new Optional([
                            new Type('string'),
                                ]),
                        'method' => new Optional([
                                ]),
                            ])
                        ]),
                    ]),
            'htmlOptions' => new Optional([
                new NotBlank(),
                new Type('array'),
                    ]),
            'columnHtmlOptions' => new Optional([
                new NotBlank(),
                new Type('array'),
                    ]),
        ]);
        $violations = $validator->validate($config, $constraint);
        // Use the same structure for the errors
        $errors = array();
        foreach ($violations as $violation) {
            /** @var ConstraintViolationInterface $violation */
            $entryErrors = (array) $propertyAccessor->getValue($errors, $violation->getPropertyPath());
            $entryErrors[] = $violation->getMessage();
            $propertyAccessor->setValue($errors, $violation->getPropertyPath(), $entryErrors);
        }
        if ($errors) {
            throw new PhpCoreException('Invalid Param : ' . json_encode($errors));
        } else {
            return true;
        }
    }

    public function validateColumn($column) {
        $validator = new Validator();
        $searchValidator = new Validator();
        $validator->requirePresence('data');
        $validator->requirePresence('name')->allowEmpty('name');
        $validator->requirePresence('searchable')->inList('searchable', ['true', 'false']);
        $validator->requirePresence('orderable')->inList('orderable', ['true', 'false']);
        $searchValidator->requirePresence('value')->allowEmpty('value');
        $searchValidator->requirePresence('regex')->inList('regex', ['true', 'false']);
        $validator->requirePresence('search')->isArray('search');
        $validator->addNested('search', $searchValidator);
        $errors = $validator->errors($column);
        if (!empty($errors)) {
            throw new PhpCoreException('Invalid Param : ' . json_encode($errors));
        }
        return true;
    }

    public function validateOrder($order) {
        $validator = new Validator();
        $validator->requirePresence('column')->numeric('column');
        $validator->requirePresence('dir')->inList('dir', ['asc', 'desc']);
        $errors = $validator->errors($order);
        if (!empty($errors)) {
            throw new PhpCoreException('Invalid Param : ' . json_encode($errors));
        }
        return true;
    }

    public function validateRequestData() {
        $validate = [
            'draw' => $this->request['draw'],
            'length' => $this->limit(),
            'start' => $this->offset(),
            'search' => $this->request['search']
        ];
        $validator = new Validator();
        $searchValidator = new Validator();
        $validator->requirePresence('draw')->numeric('draw');
//        $validator->requirePresence('length')->numeric('length');
//        $validator->requirePresence('start')->numeric('start');
        $validator->requirePresence('length')->allowEmpty('length');
        $validator->requirePresence('start')->allowEmpty('start');
        $validator->requirePresence('search')->isArray('search');
        $searchValidator->requirePresence('value')->allowEmpty('value');
        $searchValidator->requirePresence('regex')->inList('regex', ['true', 'false']);
        $validator->addNested('search', $searchValidator);
        $errors = $validator->errors($validate);
        if (!empty($errors)) {
            throw new PhpCoreException('Invalid Param : ' . json_encode($errors));
        }
        return true;
    }

}