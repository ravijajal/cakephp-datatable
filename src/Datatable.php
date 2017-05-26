<?php

namespace Jajal\Datatable;

use Cake\Http\ServerRequest;
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

class Datatable {

    public $config;
    protected $request;

    public function __construct($config, ServerRequest $request) {
        if ($this->validationConfig($config)) {
            $this->config = $config;
        }
        $this->request = $request;
    }

    public function columns() {
        return (array) $this->request->getData('columns');
    }

    public function order() {
        return (array) $this->request->getData('order');
    }

    public function limit() {
        return $this->request->getData('length');
    }

    public function offset() {
        return $this->request->getData('start');
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

    public function generate() {
        $html = '<table ' . $this->getTableHtmlOptions() . '>'
                . '<thead>'
                . '<tr>';
        foreach ($this->config('view') as $column) {
            $html.='<th ' . $this->getHtmlOptions($this->config('columnHtmlOptions')) . '>' . $column['label'] . '</th>';
        }
        $html .= '</tr>'
                . '<tr>';
        $index = 0;
        foreach ($this->config('view') as $column) {
            if (isset($column['search']) && $column['search']) {
                $htmlOptions = isset($column['htmlOptions']) ? $this->getHtmlOptions($column['htmlOptions']) : '';
                if ($column['searchType'] == 'text') {
                    $html.='<td><input type="text" ' . $htmlOptions . ' data-index="' . $index . '"></td>';
                } else if ($column['searchType'] == 'select') {
                    $html.='<td><select ' . $htmlOptions . ' data-index="' . $index . '">';
                    $html.='<option value="">All</option>';
                    foreach ($column['searchOptions']as $value => $label) {
                        $html.='<option value="' . $value . '">' . $label . '</option>';
                    }
                    $html.='</select></td>';
                }
            } else {
                $html.='<td></td>';
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
            $optionsStr .=' ' . $name . '="' . $value . '" ';
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
        $columnsArr = $this->columns();
        $orderArr = $this->order();
        $fieldArr = $this->fields();
        $query->select($fieldArr);
        $databaseConfig = $this->config('database');
        foreach ($orderArr as $order) {
            $columnName = $columnsArr[$order['column']]['data'];
            if (isset($databaseConfig[$columnName])) {
                $query->order([$databaseConfig[$columnName]['name'] => $order['dir']]);
            }
        }
        $whereArr = [];
        foreach ($columnsArr as $column) {
            if (isset($databaseConfig[$column['data']]) && $column['search']['value'] != '') {
                $whereArr[] = [$databaseConfig[$column['data']]['name'] . ' LIKE' => $column['search']['value'] . '%'];
            }
        }
        $query->where($whereArr);
        $recordsTotal = $query->count();
        $query->limit($this->limit());
        $query->offset($this->offset());
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
                                ])
                            ])
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
                        'searchOptions' => new Optional([
                            new NotBlank(),
                            new Type('array'),
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
            echo "<pre />";
            print_r($errors);
            echo "\n";
            exit;
        } else {
            return true;
        }
    }

}
