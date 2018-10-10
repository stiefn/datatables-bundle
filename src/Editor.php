<?php

namespace Omines\DataTablesBundle;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMException;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Editor {
    private $managerRegistry;
    private $validator;
    private $translator;
    private $domain;
    private $beforeCreate = null;
    private $beforeEdit = null;
    private $beforeRemove = null;

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
        $em = $this->managerRegistry->getManagerForClass($dataTable->getEntityType());
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
	    $this->beforeCreate = $function;
    }

    public function setBeforeEdit(?callable $function) {
        $this->beforeEdit = $function;
    }

    public function setBeforeRemove(?callable $function) {
        $this->beforeRemove = $function;
    }

    private function create(
        EntityManagerInterface $em,
        DataTable $dataTable,
        EditorState $state,
        array $derivedFields
    ): array {
	    $data = $state->getData();
	    if(is_array($data) && count($data) > 0) {
	        $objectData = $data[0];
            $type = $dataTable->getEntityType();
            $object = new $type();
            $mergeErrors = $this->mergeObject($em, $object, $dataTable, $objectData, $derivedFields);
            $validationErrors = $this->validate($object);
            $errors = array_merge($mergeErrors, $validationErrors);
            if(!empty($errors)) {
                return [
                    'fieldErrors' => $errors
                ];
            }
            if($this->beforeCreate !== null && !call_user_func($this->beforeCreate, $this->managerRegistry, $object)) {
                // TODO: update error
                return [
                    'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                ];
            }
            $em->persist($object);
            $em->flush();
            $output = [
                0 => $this->objectToArray($dataTable, $object)
            ];
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
                if($this->beforeEdit !== null && !call_user_func($this->beforeEdit, $this->managerRegistry, $object)) {
                    // TODO: update error
                    return [
                        'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                    ];
                }
            }
            $em->flush();
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
            if($this->beforeRemove !== null && !call_user_func($this->beforeRemove, $this->managerRegistry, $ids)) {
                // TODO: update error
                return [
                    'error' => $this->translator->trans('datatable.editor.error.emptyData', [], $this->domain)
                ];
            }
            $numDeleted = $q->execute();
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
                        $objectData[$column->getName()] = $handler($objectData[$column->getName()]);
                    }
                    $type = gettype($objectData[$column->getName()]);
                    $setterType = $reflect->getMethod($method)->getParameters()[0]->getType();
                    if($setterType !== null && $setterType->getName() !== 'string') {
                        switch($setterType->getName()) {
                            case 'int':
                                if((string)intval($objectData[$column->getName()]) !== (string)$objectData[$column->getName()]) {
                                    $errors[] = [
                                        'name' => $column->getName(),
                                        'status' => $this->translator->trans('datatable.editor.error.integerRequired', [], $this->domain)
                                    ];
                                    continue 2;
                                }
                        }
                    }
                    // if the setter requires an entity object
                    if($setterType !== null && strpos($setterType->getName(), 'App') !== false) {
                        try {
                            $object->$method($em->getReference($setterType->getName(), $objectData[$column->getName()]));
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