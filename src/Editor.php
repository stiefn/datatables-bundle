<?php

namespace Omines\DataTablesBundle;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Editor {
    private $managerRegistry;
    private $validator;
    private $translator;
    private $domain;

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
            $object = $this->mergeObject($object, $dataTable, $objectData, $derivedFields);
            $errors = $this->validate($object);
            if(!empty($errors)) {
                return $errors;
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
                $object = $this->mergeObject($object, $dataTable, $objectData, $derivedFields);
                $errors = $this->validate($object);
                if(!empty($errors)) {
                    return $errors;
                }
                $output[$id] = $this->objectToArray($dataTable, $object);
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
            $fieldErrors = [
                'fieldErrors' => []
            ];
            foreach($errors as $error) {
                $fieldErrors['fieldErrors'][] = [
                    'name' => $error->getPropertyPath(),
                    'status' => $error->getMessage()
                ];
            }
            return $fieldErrors;
        }
        return [];
    }

    private function mergeObject($object, DataTable $dataTable, array $objectData, array $derivedFields) {
        foreach($dataTable->getColumns() as $column) {
            if(isset($objectData[$column->getName()])) {
                $method = 'set' . ucfirst($column->getName());
                if(method_exists($object, $method)) {
                    $object->$method($objectData[$column->getName()]);
                }
            }
        }
        foreach($derivedFields as $field => $value) {
            $method = 'set' . ucfirst($field);
            if(method_exists($object, $method)) {
                $object->$method($value);
            }
        }
        return $object;
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