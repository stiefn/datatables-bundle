<?php

namespace Omines\DataTablesBundle;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMException;
use Omines\DataTablesBundle\Adapter\Doctrine\ORMAdapter;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Only for ORMAdapter
 */
class Editor {
    private $managerRegistry;
    private $validator;
    private $translator;
    private $domain;
    private $beforeCreate = [];
    private $beforeEdit = [];
    private $beforeRemove = [];
    private $afterCreate = [];
    private $afterEdit = [];
    private $afterRemove = [];

	public function __construct(
        ManagerRegistry $mr,
        ValidatorInterface $validator,
        TranslatorInterface $translator
    ) {
	    $this->managerRegistry = $mr;
	    $this->validator = $validator;
	    $this->translator = $translator;
    }

    public function process(DataTable $dataTable, EditorState $state, array $derivedFields = []): array {
        $this->domain = $dataTable->getTranslationDomain();
        /** @var ORMAdapter $adapter */
        $adapter = $dataTable->getAdapter();
        if($adapter->getAlternativeEntityManager() !== null) {
            $em = $this->managerRegistry->getManager($dataTable->getAdapter()->getAlternativeEntityManager());
        } else {
            $em = $this->managerRegistry->getManagerForClass($dataTable->getEntityType());
        }
        switch($state->getAction()) {
            case 'create':
                return $this->create($em, $dataTable, $state, $derivedFields);

            case 'edit':
                return $this->edit($em, $dataTable, $state, $derivedFields);

            case 'remove':
                return $this->remove($em, $dataTable, $state, $derivedFields);

            case 'upload':
                return $this->upload($em, $dataTable, $state, $derivedFields);
        }
    }

    public function setBeforeCreate(?callable $function) {
	    $this->beforeCreate[] = $function;
    }

    public function setBeforeEdit(?callable $function) {
        $this->beforeEdit[] = $function;
    }

    public function setBeforeRemove(?callable $function) {
        $this->beforeRemove[] = $function;
    }

    public function setAfterCreate(?callable $function) {
        $this->afterCreate[] = $function;
    }

    public function setAfterEdit(?callable $function) {
        $this->afterEdit[] = $function;
    }

    public function setAfterRemove(?callable $function) {
        $this->afterRemove = $function;
    }

    private function create(
        EntityManagerInterface $em,
        DataTable $dataTable,
        EditorState $state,
        array $derivedFields
    ): array {
	    $data = $state->getData();
	    if(is_array($data) && count($data) > 0) {
	        $output = [];
	        foreach($data as $key => $objectData) {
                $type = $dataTable->getEntityType();
                $object = new $type();
                $mergeErrors = $this->mergeObject($em, $object, $dataTable, $objectData, $derivedFields);
                $validationErrors = $this->validate($object);
                $errors = array_merge($mergeErrors, $validationErrors);
                if (!empty($errors)) {
                    return [
                        'fieldErrors' => $errors
                    ];
                }
                foreach ($this->beforeCreate as $beforeCreate) {
                    if (!call_user_func($beforeCreate, $this->managerRegistry, $dataTable, $object, $objectData)) {
                        // TODO: update error
                        return [
                            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                        ];
                    }
                }
                $em->persist($object);
                $em->flush();
                foreach ($this->afterCreate as $afterCreate) {
                    if (!call_user_func($afterCreate, $this->managerRegistry, $dataTable, $object, $objectData)) {
                        // TODO: update error
                        return [
                            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                        ];
                    }
                }
                $output[$key] = $this->objectToArray($dataTable, $object);
            }
            return [
                'data' => $output
            ];
        }
        return [
            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
        ];
    }

    private function edit(
        EntityManagerInterface $em,
        DataTable $dataTable,
        EditorState $state,
        array $derivedFields
    ): array {
        $data = $state->getData();
        $repository = $em->getRepository($dataTable->getEntityType());
        if(is_array($data) && count($data) > 0) {
            $output = [];
            $objects = [];
            foreach($data as $id => $objectData) {
                $object = $repository->findOneBy(['id' => $id]);
                $mergeErrors = $this->mergeObject($em, $object, $dataTable, $objectData, $derivedFields);
                $validationErrors = $this->validate($object);
                $errors = array_merge($mergeErrors, $validationErrors);
                if(!empty($errors)) {
                    return [
                        'fieldErrors' => $errors
                    ];
                }
                $output[$id] = $this->objectToArray($dataTable, $object);
                $objects[] = [
                    'object' => $object,
                    'objectData' => $objectData
                ];
                foreach($this->beforeEdit as $beforeEdit) {
                    if(!call_user_func($beforeEdit, $this->managerRegistry, $dataTable, $object, $objectData)) {
                        // TODO: update error
                        return [
                            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                        ];
                    }
                }
            }
            $em->flush();
            foreach($objects as $object) {
                foreach($this->afterEdit as $afterEdit) {
                    if(!call_user_func($afterEdit, $this->managerRegistry, $dataTable, $object['object'], $object['objectData'])) {
                        // TODO: update error
                        return [
                            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                        ];
                    }
                }
            }
            return [
                'data' => $output
            ];
        }
        return [
            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
        ];
    }

    private function remove(
        EntityManagerInterface $em,
        DataTable $dataTable,
        EditorState $state,
        array $derivedFields
    ): array {
        $data = $state->getData();
        if(is_array($data) && count($data) > 0) {
            $ids = [];
            foreach ($data as $row) {
                $ids[] = $row['id'];
            }
            $q = $em->createQuery('DELETE FROM ' . $dataTable->getEntityType() . ' o WHERE o.id IN (' .
                implode(', ', $ids) . ')');
            foreach($this->beforeRemove as $beforeRemove) {
                if(!call_user_func($beforeRemove, $this->managerRegistry, $dataTable, $object, $objectData)) {
                    // TODO: update error
                    return [
                        'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                    ];
                }
            }
            $q->execute();
            foreach($this->afterRemove as $afterRemove) {
                if(!call_user_func($afterRemove, $this->managerRegistry, $dataTable, $object, $objectData)) {
                    // TODO: update error
                    return [
                        'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                    ];
                }
            }
            return [];
        }
        return [
            'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
        ];
    }

    private function upload(
        EntityManagerInterface $em,
        DataTable $dataTable,
        EditorState $state,
        array $derivedFields
    ): array {
	    $uploadField = $state->getUploadField();
        $upload = $state->getUpload();
        if($uploadField !== null && $upload !== null) {
            foreach($dataTable->getColumns() as $column) {
                if ($column->getName() === $uploadField) {
                    if($column->getUploadHandler() !== null) {
                        return call_user_func($column->getUploadHandler(), $upload);
                    }
                    // TODO: move uploaded file and return
                    return [
                        'upload' => [
                            'id' => 1
                        ],
                        'files' => [
                            'file' => [
                                1 => ''
                            ]
                        ]
                    ];
                }
            }
        }
        return [
            'error' => $this->translator->trans('datatable.editor.error.emptyUpload', [], $this->domain)
        ];
    }

    private function validate($object): array {
        $errors = $this->validator->validate($object);
        if(count($errors) > 0) {
            $fieldErrors = [];
            foreach($errors as $error) {
                $fieldErrors[] = [
                    'name' => $error->getPropertyPath(),
                    'status' => $error->getMessage()
                ];
            }
            return $fieldErrors;
        }
        return [];
    }

    private function mergeObject(
        EntityManagerInterface $em,
        $object,
        DataTable $dataTable,
        array $objectData,
        array $derivedFields
    ) {
        $reflect = new \ReflectionClass(get_class($object));
        $errors = [];
        foreach($dataTable->getColumns() as $column) {
            if(isset($objectData[$column->getName()])) {
                $method = 'set' . ucfirst($column->getName());
                if(method_exists($object, $method)) {
                    $handler = $column->getDataHandler();
                    if($handler !== null) {
                        //echo $column->getName() . ' ' . $handler($objectData, $objectData[$column->getName()]);
                        $objectData[$column->getName()] = $handler($objectData, $objectData[$column->getName()]);
                    }
                    $setterType = $reflect->getMethod($method)->getParameters()[0]->getType();
                    if($setterType !== null && $setterType->getName() !== 'string') {
                        switch($setterType->getName()) {
                            case 'int':
                                if(!$setterType->allowsNull() || $objectData[$column->getName()] !== '') {
                                    if ((string)intval($objectData[$column->getName()]) !== (string)$objectData[$column->getName()]) {
                                        $errors[] = [
                                            'name' => $column->getName(),
                                            'status' => $this->translator->trans('datatable.editor.error.integerRequired', [], $this->domain)
                                        ];
                                        continue 2;
                                    }
                                } else if($setterType->allowsNull()) {
                                    $objectData[$column->getName()] = null;
                                }
                        }
                    }
                    // if the setter requires an entity object
                    if($setterType !== null && strpos($setterType->getName(), 'App') !== false) {
                        try {
                            if($objectData[$column->getName()] !== null && $objectData[$column->getName()] !== '') {
                                $object->$method($em->getReference($setterType->getName(), $objectData[$column->getName()]));
                            } else {
                                if(!$column->isHidden()) {
                                    $errors[] = [
                                        'name' => $column->getName(),
                                        'status' => $this->translator->trans('datatable.editor.error.entityRequired', [], $this->domain)
                                    ];
                                }
                            }
                        } catch(ORMException $e) {
                            $errors[] = [
                                'name' => $column->getName(),
                                'status' => $this->translator->trans('blub', [], $this->domain)
                            ];
                        }
                    } else {
                        $object->$method($objectData[$column->getName()]);
                    }
                }
                if($column->isRequired()) {
                    if(($column->isFileMany() && count($objectData[$column->getName()]) === 0)
                        || (!$column->isFileMany() && strlen($objectData[$column->getName()]) === 0))
                    $errors[] = [
                        'name' => $column->getName(),
                        'status' => $this->translator->trans('datatable.editor.error.fieldRequired', [], $this->domain)
                    ];
                }
            }
            if($column->isRequired() && !isset($objectData[$column->getName()])) {
                $errors[] = [
                    'name' => $column->getName(),
                    'status' => $this->translator->trans('datatable.editor.error.fieldRequired', [], $this->domain)
                ];
            }
        }
        foreach($derivedFields as $field => $value) {
            $method = 'set' . ucfirst($field);
            if(method_exists($object, $method)) {
                $object->$method($value);
            }
        }

        return $errors;
    }

    private function objectToArray(DataTable $dataTable, $object) {
	    $array = [];
        $type = $dataTable->getEntityType();
        foreach($dataTable->getColumns() as $column) {
            $method = 'get' . ucfirst($column->getName());
            if(method_exists($type, $method)) {
                $array[$column->getName()] = $object->$method();
            }
        }
        return $array;
    }
}