<?php

namespace Omines\DataTablesBundle;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class EditorState {
    private $action;
    private $subActions = [];
    private $data = null;
    private $uploadField = null;
    private $upload = null;

    public function setDataAction(string $action, array $data) {
        $this->action = $action;
        $this->data = $data;
    }

    public function setUploadAction(string $action, string $uploadField, UploadedFile $upload) {
        $this->action = $action;
        $this->uploadField = $uploadField;
        $this->upload = $upload;
    }

    public function setSubActions(array $subActions) {
        $this->subActions = $subActions;
    }

    public function getAction(): string {
	    return $this->action;
    }

    public function getSubActions(): array {
        return $this->subActions;
    }

    public function getData(): ?array {
	    return $this->data;
    }

    public function getUploadField(): ?string {
        return $this->uploadField;
    }

    public function getUpload(): ?UploadedFile {
        return $this->upload;
    }
}