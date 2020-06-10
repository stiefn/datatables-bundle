<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle;

use Mpdf\Tag\Bookmark;
use Omines\DataTablesBundle\Adapter\AdapterInterface;
use Omines\DataTablesBundle\Adapter\ResultSetInterface;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\Column\BoolColumn;
use Omines\DataTablesBundle\Column\MapColumn;
use Omines\DataTablesBundle\DependencyInjection\Instantiator;
use Omines\DataTablesBundle\Exception\InvalidArgumentException;
use Omines\DataTablesBundle\Exception\InvalidConfigurationException;
use Omines\DataTablesBundle\Exception\InvalidStateException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DataTable.
 *
 * @author Robbert Beesems <robbert.beesems@omines.com>
 */
class DataTable
{
    const DEFAULT_OPTIONS = [
        'jQueryUI' => false,
        'pagingType' => 'full_numbers',
        'lengthMenu' => [[10, 25, 50, -1], [10, 25, 50, 'All']],
        'pageLength' => 10,
        'displayStart' => 0,
        'serverSide' => true,
        'processing' => true,
        'paging' => true,
        'lengthChange' => true,
        'ordering' => true,
        'searching' => false,
        'search' => null,
        'autoWidth' => false,
        'order' => [],
        'searchDelay' => 400,
        'dom' => 'lftrip',
        'orderCellsTop' => true,
        'stateSave' => false,
        'fixedHeader' => false,
    ];

    const DEFAULT_TEMPLATE = '@DataTables/datatable_html.html.twig';
    const SORT_ASCENDING = 'asc';
    const SORT_DESCENDING = 'desc';

    /** @var AdapterInterface */
    protected $adapter;

    /** @var AbstractColumn[] */
    protected $columns = [];

    /** @var AbstractColumn[] */
    protected $columnsWithoutHidden = [];

    /** @var array<string, AbstractColumn> */
    protected $columnsByName = [];

    /** @var string */
    protected $method = Request::METHOD_POST;

    /** @var array */
    protected $options;

    /** @var bool */
    protected $languageFromCDN = true;

    /** @var string */
    protected $name = 'dt';

    /** @var string */
    protected $persistState = 'fragment';

    /** @var string */
    protected $template = self::DEFAULT_TEMPLATE;

    /** @var array */
    protected $templateParams = [];

    /** @var callable */
    protected $transformer;

    /** @var string */
    protected $translationDomain = 'messages';

    /** @var bool */
    protected $useEditor = false;

    /** @var string */
    protected $entityType = null;

    /** @var Editor */
    protected $editor = null;

    /** @var DataTableRendererInterface */
    private $renderer;

    /** @var DataTableState */
    private $state;

    /** @var EditorState */
    private $editorState;

    /** @var Instantiator */
    private $instantiator;

    /** @var TranslatorInterface */
    private $translator;

    /** @var string[] */
    private $editorButtons = [];

    /** @var DataTable[]  */
    private $children = [];

    /** @var string[]  */
    private $childrenUrls = [];

    private $allowInlineEditing = true;

    private $groupingColumn = null;

    private $childRowColumns = null;

    private $groupCreationFields = null;

    private $groupCreationField = null;

    private $reorderingEnabled = false;

    private $reorderingConstraintField = null;

    private $validationGroup = 'Default';

    private $groupingConstraintField = null;

    /**
     * DataTable constructor.
     *
     * @param array $options
     * @param Instantiator|null $instantiator
     */
    public function __construct(array $options = [], Instantiator $instantiator = null)
    {
        $this->instantiator = $instantiator ?? new Instantiator();

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options = $resolver->resolve($options);
    }

    /**
     * @param string $name
     * @param string $type
     * @param array $options
     * @return $this
     */
    public function add(string $name, string $type, array $options = [])
    {
        // Ensure name is unique
        if (isset($this->columnsByName[$name])) {
            throw new InvalidArgumentException(sprintf("There already is a column with name '%s'", $name));
        }

        $column = $this->instantiator->getColumn($type);
        $column->initialize($name, count($this->columnsWithoutHidden), $options, $this);

        $this->columns[] = $column;
        if(!$column->isHidden()) {
            $this->columnsWithoutHidden[] = $column;
        }
        $this->columnsByName[$name] = $column;

        return $this;
    }

    public function addChild(DataTable $child, string $name, string $editUrl) {
        $this->children[$name] = $child;
        $this->childrenUrls[$name] = $editUrl;
    }

    public function setEditorButtons(array $editorButtons): self {
        $this->editorButtons = $editorButtons;
        return $this;
    }

    public function hasEditorButton(string $buttonName) {
        foreach($this->editorButtons as $button) {
            if($button == $buttonName) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int|string|AbstractColumn $column
     * @param string $direction
     * @return $this
     */
    public function addOrderBy($column, string $direction = self::SORT_ASCENDING)
    {
        if (!$column instanceof AbstractColumn) {
            $column = is_int($column) ? $this->getColumn($column) : $this->getColumnByName((string) $column);
        }
        $this->options['order'][] = [$column->getIndex(), $direction];

        return $this;
    }

    /**
     * @param string $adapter
     * @return $this
     */
    public function createAdapter(string $adapter, array $options = []): self
    {
        return $this->setAdapter($this->instantiator->getAdapter($adapter), $options);
    }

    /**
     * @return AdapterInterface
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * @param int $index
     * @return AbstractColumn
     */
    public function getColumn(int $index): AbstractColumn
    {
        if ($index < 0 || $index >= count($this->columnsWithoutHidden)) {
            throw new InvalidArgumentException(sprintf('There is no column with index %d', $index));
        }

        return $this->columnsWithoutHidden[$index];
    }

    /**
     * @param string $name
     * @return AbstractColumn
     */
    public function getColumnByName(string $name): AbstractColumn
    {
        if (!isset($this->columnsByName[$name])) {
            throw new InvalidArgumentException(sprintf("There is no column named '%s'", $name));
        }

        return $this->columnsByName[$name];
    }

    /**
     * @return AbstractColumn[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return bool
     */
    public function isLanguageFromCDN(): bool
    {
        return $this->languageFromCDN;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return bool
     */
    public function getUseEditor(): bool
    {
        return $this->useEditor;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPersistState(): string
    {
        return $this->persistState;
    }

    /**
     * @return DataTableState|null
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @return EditorState|null
     */
    public function getEditorState()
    {
        return $this->editorState;
    }

    /**
     * @return string
     */
    public function getTranslationDomain(): string
    {
        return $this->translationDomain;
    }

    /**
     * @return bool
     */
    public function isCallback(): bool
    {
        return (null === $this->state) ? false : $this->state->isCallback();
    }

    /**
     * @return bool
     */
    public function isEditorCallback(): bool
    {
        return (null !== $this->editorState);
    }

    /**
     * @param Request $request
     * @return $this
     */
    public function handleRequest(Request $request): self
    {
        switch ($request->getMethod()) {
            case Request::METHOD_GET:
                $parameters = $request->query;
                break;
            case Request::METHOD_POST:
                $parameters = $request->request;
                break;
            default:
                throw new InvalidConfigurationException(sprintf("Unknown request method '%s'", $this->getMethod()));
        }
        if ($this->getName() === $parameters->get('_dt')) {
            // handle request for datatable drawing
            if (null === $this->state) {
                $this->state = DataTableState::fromDefaults($this);
            }
            $this->state->applyParameters($parameters);
        } else if($parameters->get('action') !== null) {
            // handle request for datatable editor actions
            if (null === $this->editorState) {
                $this->editorState = new EditorState();

                if($parameters->get('subActions') && is_array($parameters->get('subActions'))) {
                    $this->editorState->setSubActions($parameters->get('subActions'));
                }

                if($parameters->get('data')) {
                    $this->editorState->setDataAction($parameters->get('action'), $parameters->get('data'));
                } else if($request->files->get('upload') !== null) {
                    $this->editorState->setUploadAction(
                        $parameters->get('action'),
                        $parameters->get('uploadField'),
                        $request->files->get('upload')
                    );
                }
            }
        }

        return $this;
    }

    /**
     * @return JsonResponse
     */
    public function getResponse(): JsonResponse
    {
        if (null === $this->state) {
            throw new InvalidStateException('The DataTable does not know its state yet, did you call handleRequest?');
        }

        $resultSet = $this->getResultSet();
        $response = [
            'draw' => 0,
            'recordsTotal' => $resultSet->getTotalRecords(),
            'recordsFiltered' => $resultSet->getTotalDisplayRecords(),
            'data' => iterator_to_array($resultSet->getData())
        ];
        if ($this->state->isInitial()) {
            $response['options'] = $this->getInitialResponse();
            if($this->getUseEditor()) {
                $response['editorOptions'] = $this->getInitialEditorResponse();
                $response['editorButtons'] = $this->editorButtons;
                $response['groupingEnabled'] = $this->groupingEnabled();
                if($this->groupingEnabled()) {
                    $response['groupingColumn'] = $this->getGroupingColumn();
                    $response['groupCreationField'] = $this->getGroupCreationField();
                    $response['groupCreationIds'] = $this->getGroupCreationIds();
                    $response['childRowColumns'] = $this->getChildRowColumns();
                    $response['groupingConstraintField'] = $this->getGroupingConstraintField();
                }
                $response['reorderingEnabled'] = $this->reorderingEnabled();
                if($this->reorderingEnabled()) {
                    $response['reorderingConstraintField'] = $this->getReorderingConstraintField();
                }
                if(count($this->children) > 0) {
                    $response['childEditorOptions'] = [];
                    $response['childEditorUrls'] = $this->childrenUrls;
                    foreach($this->children as $name => $child) {
                        $response['childEditorOptions'][$name] = $child->getInitialEditorResponse();
                    }
                }
            }
            $response['template'] = $this->renderer->renderDataTable($this, $this->template, $this->templateParams);
        }

        return JsonResponse::create($response);
    }

    public function getConfig() {
        $response = [
            'draw' => 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ];
        $response['options'] = $this->getInitialResponse();
        if($this->getUseEditor()) {
            $response['editorOptions'] = $this->getInitialEditorResponse();
            $response['editorButtons'] = $this->editorButtons;
            $response['groupingEnabled'] = $this->groupingEnabled();
            if($this->groupingEnabled()) {
                $response['groupingColumn'] = $this->getGroupingColumn();
                $response['groupCreationField'] = $this->getGroupCreationField();
                $response['groupCreationIds'] = $this->getGroupCreationIds();
                $response['childRowColumns'] = $this->getChildRowColumns();
                $response['groupingConstraintField'] = $this->getGroupingConstraintField();
            }
            $response['reorderingEnabled'] = $this->reorderingEnabled();
            if($this->reorderingEnabled()) {
                $response['reorderingConstraintField'] = $this->getReorderingConstraintField();
            }
            if(count($this->children) > 0) {
                $response['childEditorOptions'] = [];
                $response['childEditorUrls'] = $this->childrenUrls;
                foreach($this->children as $name => $child) {
                    $response['childEditorOptions'][$name] = $child->getInitialEditorResponse();
                }
            }
        }
        $response['template'] = $this->renderer->renderDataTable($this, $this->template, $this->templateParams);
        return $response;
    }

    public function getEditorResponse(array $derivedFields = [], string $childName = null): JsonResponse {
        if (null === $this->editorState) {
            throw new InvalidStateException('No Editor state available, did you call handleRequest?');
        }

        $dt = $this;
        if($childName !== null) {
            $dt = $this->children[$childName];
        }
        return JsonResponse::create($dt->editor->process($dt, $this->editorState, $derivedFields));

    }

    public function getEditor(): ?Editor {
        return $this->editor;
    }

    protected function getInitialResponse(): array
    {
        $map = [];
        $i = 0;
        foreach($this->getColumns() as $column) {
            if(!$column->isHidden()) {
                $map[$i] = [
                    'data' => $column->getName(),
                    'orderable' => $column->isOrderable(),
                    'searchable' => $column->isSearchable(),
                    'visible' => $column->isVisible(),
                    'className' => $column->getClassName()
                ];
                if($column->isEditable() && $column->isInlineEditable() && $this->allowInlineEditing) {
                    $map[$i]['className'] .= ' editable';
                }
                if($column->getMap() !== null) {
                    $map[$i]['map'] = $column->getMap();
                } else if($column->getRenderedLength() !== null) {
                    $map[$i]['renderedLength'] = $column->getRenderedLength();
                }
                if($column->isImage()) {
                    $map[$i]['imageUrlPrefix'] = $column->getImageUrlPrefix();
                }
                if($column->isDate()) {
                    $map[$i]['type'] = 'date';
                    $map[$i]['dateFormat'] = $column->getFormat();
                }
                ++$i;
            }
        }
        return array_merge($this->getOptions(), [
            'columns' => $map
        ]);
    }

    public function getInitialEditorResponse(): array
    {
        $map = [];
        $i = 0;
        foreach($this->getColumns() as $column) {
            if($column->isEditable()) {
                $map[$i] = [
                    'label' => $column->getLabel(),
                    'name' => $column->getName()
                ];
                if($column->isFile()) {
                    $map[$i]['type'] = 'upload';
                    $map[$i]['uploadText'] = $this->translator->trans('datatable.editor.fileUpload.uploadText', [], $this->translationDomain);
                    $map[$i]['dragDropText'] = $this->translator->trans('datatable.editor.fileUpload.dragDropText', [], $this->translationDomain);
                    $map[$i]['noFileText'] = $this->translator->trans('datatable.editor.fileUpload.noFileText', [], $this->translationDomain);
                }
                if($column->isFileMany()) {
                    $map[$i]['type'] = 'uploadMany';
                    $map[$i]['uploadText'] = $this->translator->trans('datatable.editor.fileUpload.uploadText', [], $this->translationDomain);
                    $map[$i]['dragDropText'] = $this->translator->trans('datatable.editor.fileUpload.dragDropText', [], $this->translationDomain);
                    $map[$i]['noFileText'] = $this->translator->trans('datatable.editor.fileUpload.noFileText', [], $this->translationDomain);
                }
                if($column->getNormalizedMap() !== null) {
                    $map[$i]['type'] = 'select';
                    $mapping = [];
                    foreach($column->getNormalizedMap() as $key => $value) {
                        $mapping[] = [
                            'label' => $value,
                            'value' => $key
                        ];
                    }
                    $map[$i]['options'] = $mapping;
                }
                $lines = $column->getOption('lines');
                if(!is_null($lines) && $lines > 1) {
                    $map[$i]['type'] = 'textarea';
                    $map[$i]['attr'] = [
                        'rows' => $lines
                    ];
                }
                $maxLength = $column->getOption('maxLength');
                if(!is_null($maxLength) && $maxLength > 1) {
                    $map[$i]['attr'] = [
                        'maxlength' => $maxLength
                    ];
                }
                if($column->getFieldOptions()) {
                    $map[$i]['options'] = $column->getFieldOptions();
                }
                if($column->isDate()) {
                    $map[$i]['type'] = 'date';
                    $map[$i]['dateFormat'] = $column->getFormat();
                }
                if($column->getDefaultValue() !== null) {
                    $map[$i]['def'] = $column->getDefaultValue();
                }
                if($column->getType() !== null) {
                    $map[$i]['type'] = $column->getType();
                }
                if($column->isHidden() || $column->isHiddenInput()) {
                    $map[$i]['type'] = 'hidden';
                }
                if($column->isHiddenInDialog()) {
                    $map[$i]['className'] = 'hidden-input';
                }
                if(!$column->isComparable()) {
                    $map[$i]['compare'] = 'function(a,b){return false;}';
                }
                ++$i;
            }
        }

        return [
            'fields' => $map
        ];
    }

    /**
     * @return ResultSetInterface
     */
    protected function getResultSet(): ResultSetInterface
    {
        if (null === $this->adapter) {
            throw new InvalidStateException('No adapter was configured yet to retrieve data with. Call "createAdapter" or "setAdapter" before attempting to return data');
        }

        return $this->adapter->getData($this->state);
    }

    /**
     * @return callable|null
     */
    public function getTransformer()
    {
        return $this->transformer;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param $name
     * @return mixed|null
     */
    public function getOption($name)
    {
        return $this->options[$name] ?? null;
    }

    public function getEntityType(): string {
        return $this->entityType;
    }

    /**
     * @param AdapterInterface $adapter
     * @param array|null $options
     * @return DataTable
     */
    public function setAdapter(AdapterInterface $adapter, array $options = null): self
    {
        if (null !== $options) {
            $adapter->configure($options);
        }
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @param bool $languageFromCDN
     * @return $this
     */
    public function setLanguageFromCDN(bool $languageFromCDN): self
    {
        $this->languageFromCDN = $languageFromCDN;

        return $this;
    }

    /**
     * @param string $method
     * @return $this
     */
    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * @param bool $useEditor
     * @return $this
     */
    public function useEditor(bool $useEditor): self
    {
        $this->useEditor = $useEditor;

        return $this;
    }

    /**
     * @param Editor $editor
     */
    public function setEditor(Editor $editor) {
        $this->editor = $editor;

        return $this;
    }

    /**
     * @param string $entityType
     * @return $this
     */
    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;

        return $this;
    }

    /**
     * @param string $persistState
     * @return $this
     */
    public function setPersistState(string $persistState): self
    {
        $this->persistState = $persistState;

        return $this;
    }

    /**
     * @param DataTableRendererInterface $renderer
     * @return $this
     */
    public function setRenderer(DataTableRendererInterface $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self
    {
        if (empty($name)) {
            throw new InvalidArgumentException('DataTable name cannot be empty');
        }
        $this->name = $name;

        return $this;
    }

    /**
     * @param string $template
     * @return $this
     */
    public function setTemplate(string $template, array $parameters = []): self
    {
        $this->template = $template;
        $this->templateParams = $parameters;

        return $this;
    }

    /**
     * @param string $translationDomain
     * @return $this
     */
    public function setTranslationDomain(string $translationDomain): self
    {
        $this->translationDomain = $translationDomain;

        return $this;
    }

    public function setTranslator(TranslatorInterface $translator): self
    {
        $this->translator = $translator;

        return $this;
    }

    /**
     * @param callable $formatter
     * @return $this
     */
    public function setTransformer(callable $formatter)
    {
        $this->transformer = $formatter;

        return $this;
    }

    /**
     * @param OptionsResolver $resolver
     * @return $this
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(self::DEFAULT_OPTIONS);

        return $this;
    }

    public function allowInlineEditing(bool $allow) {
        $this->allowInlineEditing = $allow;
    }

    public function getGroupingColumn(): ?string {
        return $this->groupingColumn;
    }

    public function setGroupingColumn(?string $columnName): self {
        $this->groupingColumn = $columnName;
        return $this;
    }

    public function groupingEnabled(): bool {
        return $this->groupingColumn === null ? false : true;
    }

    public function reorderingEnabled(): bool {
        return $this->reorderingEnabled;
    }

    public function pagingEnabled(): bool {
        if($this->reorderingEnabled() || $this->groupingEnabled()) {
            return false;
        }
        return true;
    }

    public function getChildRowColumns(): ?array {
        return $this->childRowColumns;
    }

    public function setChildRowColumns(?array $childRowColumns): self {
        $this->childRowColumns = $childRowColumns;
        return $this;
    }

    public function getGroupCreationIds(): ?array {
        return $this->groupCreationIds;
    }

    public function setGroupCreationIds(?array $groupCreationIds): self {
        $this->groupCreationIds = $groupCreationIds;
        return $this;
    }

    public function getGroupCreationField(): ?string {
        return $this->groupCreationField;
    }

    public function setGroupCreationField(?string $groupCreationField): self {
        $this->groupCreationField = $groupCreationField;
        return $this;
    }

    public function setReorderingEnabled(bool $reorderingEnabled): self {
        $this->reorderingEnabled = $reorderingEnabled;
        return $this;
    }

    public function setReorderingConstraintField(?string $field) {
        $this->reorderingConstraintField = $field;
        return $this;
    }

    public function getReorderingConstraintField(): ?string {
        return $this->reorderingConstraintField;
    }

    public function setValidationGroup(string $validationGroup): self {
        $this->validationGroup = $validationGroup;
        return $this;
    }

    public function getValidationGroup(): string {
        return $this->validationGroup;
    }

    public function setGroupingConstraintField(?string $field) {
        $this->groupingConstraintField = $field;
        return $this;
    }

    public function getGroupingConstraintField(): ?string {
        return $this->groupingConstraintField;
    }
}
