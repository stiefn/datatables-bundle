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

	public function __construct(ManagerRegistry $mr, ValidatorInterface $validator, TranslatorInterface $translator) {
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

            case 'remove':
            return $this->remove($em, $dataTable, $state, $derivedFields);

            case 'upload':
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
            $object = $this->createObject($dataTable, $objectData, $derivedFields);
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

    private function remove(
        EntityManagerInterface $em,
        DataTable $dataTable,
        EditorState $state,
        array $derivedFields
    ): array {
        $data = $state->getData();
        $ids = [];
        foreach($data as $row) {
            $ids[] = $row['id'];
        }
        $q = $em->createQuery('DELETE FROM ' . $dataTable->getEntityType() . ' o WHERE o.id IN (' .
            implode(', ', $ids) . ')');
        $numDeleted = $q->execute();
        return [];
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

    private function createObject(DataTable $dataTable, array $objectData, array $derivedFields) {
        $type = $dataTable->getEntityType();
        $object = new $type();
        foreach($dataTable->getColumns() as $column) {
            if($column->getName() !== 'id') {
                $method = 'set' . ucfirst($column->getName());
                if(method_exists($type, $method)) {
                    $object->$method($objectData[$column->getName()]);
                }
            }
        }
        foreach($derivedFields as $field => $value) {
            $method = 'set' . ucfirst($field);
            if(method_exists($type, $method)) {
                $object->$method($value);
            }
        }
    }

    private function objectToArray(DataTable $dataTable, $object) {
	    $array = [];
        foreach($dataTable->getColumns() as $column) {
            $method = 'get' . ucfirst($column->getName());
            if(method_exists($type, $method)) {
                $array[$column->getName()] = $object->$method();
            }
        }
        return $array;
    }
}