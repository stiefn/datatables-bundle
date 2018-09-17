<?php

namespace Omines\DataTablesBundle;

class EditorState {
    private $action;
    private $data;

	public function __construct(string $action, array $data) {
	    $this->action = $action;
	    $this->data = $data;
    }

    public function getAction(): string {
	    return $this->action;
    }

    public function getData(): array {
	    return $this->data;
    }
}