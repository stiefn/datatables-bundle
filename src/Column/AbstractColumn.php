<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Column;

use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\Filter\AbstractFilter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * AbstractColumn.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
abstract class AbstractColumn
{
    /** @var array<string, OptionsResolver> */
    private static $resolversByClass = [];

    /** @var string */
    private $name;

    /** @var int */
    private $index;

    /** @var DataTable */
    private $dataTable;

    /** @var array<string, mixed> */
    protected $options;

    /**
     * @param string $name
     * @param int $index
     * @param array $options
     * @param DataTable $dataTable
     */
    public function initialize(string $name, int $index, array $options = [], DataTable $dataTable)
    {
        $this->name = $name;
        $this->index = $index;
        $this->dataTable = $dataTable;

        $class = get_class($this);
        if (!isset(self::$resolversByClass[$class])) {
            self::$resolversByClass[$class] = new OptionsResolver();
            $this->configureOptions(self::$resolversByClass[$class]);
        }
        $this->options = self::$resolversByClass[$class]->resolve($options);
    }

    /**
     * The transform function is responsible for converting column-appropriate input to a datatables-usable type.
     *
     * @param mixed|null $value The single value of the column, if mapping makes it possible to derive one
     * @param mixed|null $context All relevant data of the entire row
     * @return mixed
     */
    public function transform($value = null, $context = null)
    {
        $data = $this->getData();
        if (is_callable($data)) {
            $value = call_user_func($data, $context, $value);
        } elseif (null === $value) {
            $value = $data;
        }

        return $this->render($this->normalize($value), $context);
    }

    /**
     * Apply final modifications before rendering to result.
     *
     * @param mixed $value
     * @param mixed $context All relevant data of the entire row
     * @return mixed|string
     */
    protected function render($value, $context)
    {
        return $value;
    }

    /**
     * The normalize function is responsible for converting parsed and processed data to a datatables-appropriate type.
     *
     * @param mixed $value The single value of the column
     * @return mixed
     */
    abstract public function normalize($value);

    /**
     * @param OptionsResolver $resolver
     * @return $this
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setDefaults([
                'label' => null,
                'data' => null,
                'field' => null,
                'propertyPath' => null,
                'visible' => true,
                'orderable' => null,
                'orderField' => null,
                'searchable' => null,
                'globalSearchable' => null,
                'filter' => null,
                'className' => null,
                'render' => null,
                'leftExpr' => null,
                'operator' => '=',
                'rightExpr' => null,
                'editable' => true,
                'inlineEditable' => true,
                'file' => false,
                'fileMany' => false,
                'uploadHandler' => null,
                'dataHandler' => null,
                'hidden' => false,
                'hiddenInput' => false,
                'hiddenInDialog' => false,
                'type' => null,
                'defaultValue' => null,
                'options' => null,
                'required' => false,
                'comparable' => true,
                'imageUrlPrefix' => null
            ])
            ->setAllowedTypes('label', ['null', 'string'])
            ->setAllowedTypes('data', ['null', 'string', 'callable'])
            ->setAllowedTypes('field', ['null', 'string'])
            ->setAllowedTypes('propertyPath', ['null', 'string'])
            ->setAllowedTypes('visible', 'boolean')
            ->setAllowedTypes('orderable', ['null', 'boolean'])
            ->setAllowedTypes('orderField', ['null', 'string'])
            ->setAllowedTypes('searchable', ['null', 'boolean'])
            ->setAllowedTypes('globalSearchable',  ['null', 'boolean'])
            ->setAllowedTypes('filter', ['null', AbstractFilter::class])
            ->setAllowedTypes('className', ['null', 'string'])
            ->setAllowedTypes('render', ['null', 'int', 'array'])
            ->setAllowedTypes('operator', ['string'])
            ->setAllowedTypes('leftExpr', ['null', 'string', 'callable'])
            ->setAllowedTypes('rightExpr', ['null', 'string', 'callable'])
            ->setAllowedTypes('editable', ['null', 'boolean'])
            ->setAllowedTypes('inlineEditable', ['null', 'boolean'])
            ->setAllowedTypes('file', ['null', 'boolean'])
            ->setAllowedTypes('fileMany', ['null', 'boolean'])
            ->setAllowedTypes('dataHandler', ['null', 'callable'])
            ->setAllowedTypes('hidden', ['boolean'])
            ->setAllowedTypes('hiddenInput', ['boolean'])
            ->setAllowedTypes('hiddenInDialog', ['boolean'])
            ->setAllowedTypes('type', ['null', 'string'])
            ->setAllowedTypes('defaultValue', ['null', 'int', 'string'])
            ->setAllowedTypes('options', ['null', 'array'])
            ->setAllowedTypes('required', ['null', 'boolean'])
            ->setAllowedTypes('comparable', ['boolean'])
            ->setAllowedTypes('imageUrlPrefix', ['null', 'string'])
        ;

        return $this;
    }

    /**
     * @return int
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string|null
     */
    public function getLabel()
    {
        return $this->options['label'] ?? "{$this->dataTable->getName()}.columns.{$this->getName()}";
    }

    /**
     * @return string|null
     */
    public function getField()
    {
        return $this->options['field'];
    }

    /**
     * @return string|null
     */
    public function getPropertyPath()
    {
        return $this->options['propertyPath'];
    }

    /**
     * @return callable|string|null
     */
    public function getData()
    {
        return $this->options['data'];
    }

    /**
     * @return bool
     */
    public function isVisible(): bool
    {
        return $this->options['visible'];
    }

    /**
     * @return bool
     */
    public function isSearchable(): bool
    {
        return $this->options['searchable'] ?? !empty($this->getField());
    }

    /**
     * @return bool
     */
    public function isOrderable(): bool
    {
        return $this->options['orderable'] ?? !empty($this->getOrderField());
    }

    /**
     * @return AbstractFilter
     */
    public function getFilter()
    {
        return $this->options['filter'];
    }

    /**
     * @return string|null
     */
    public function getOrderField()
    {
        return $this->options['orderField'] ?? $this->getField();
    }

    /**
     * @return bool
     */
    public function isGlobalSearchable(): bool
    {
        return $this->options['globalSearchable'] ?? $this->isSearchable();
    }

    /**
     * @return string
     */
    public function getLeftExpr()
    {
        $leftExpr = $this->options['leftExpr'];
        if ($leftExpr === null) return $this->getField();
        if (is_callable($leftExpr)) {
            return call_user_func($leftExpr, $this->getField());
        }
        return $leftExpr;
    }

    /**
     * @return mixed
     */
    public function getRightExpr($value)
    {
        $rightExpr = $this->options['rightExpr'];
        if ($rightExpr === null) return $value;
        if (is_callable($rightExpr)) {
            return call_user_func($rightExpr, $value);
        }
        return $rightExpr;
    }

    /**
     * @return string
     */
    public function getOperator()
    {
        return $this->options['operator'];
    }

    /**
     * @return string
     */
    public function getClassName()
    {
        return $this->options['className'];
    }

    /**
     * @return DataTable
     */
    public function getDataTable(): DataTable
    {
        return $this->dataTable;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function setOption(string $name, $value): self
    {
        $this->options[$name] = $value;

        return $this;
    }

    public function getOption(string $name)
    {
        if(isset($this->options[$name])) {
            return $this->options[$name];
        }
        return null;
    }

    public function isHidden(): bool {
        return $this->options['hidden'];
    }

    public function isHiddenInput(): bool {
        return $this->options['hiddenInput'];
    }

    public function isHiddenInDialog(): bool {
        return $this->options['hiddenInDialog'];
    }

    /**
     * @param string $value
     * @return bool
     */
    public function isValidForSearch($value)
    {
        return true;
    }

    public function isEditable(): bool
    {
        return $this->options['editable'];
    }

    public function isInlineEditable(): bool
    {
        return $this->options['inlineEditable'];
    }

    public function isImage(): bool
    {
        return $this->options['imageUrlPrefix'] !== null;
    }

    public function getImageUrlPrefix(): string
    {
        return $this->options['imageUrlPrefix'];
    }

    public function isFile(): bool
    {
        return $this->options['file'];
    }

    public function isFileMany(): bool
    {
        return $this->options['fileMany'];
    }

    public function getUploadHandler(): ?callable {
        return $this->options['uploadHandler'];
    }

    public function getDataHandler(): ?callable {
        return $this->options['dataHandler'];
    }

    public function getRender() {
        return $this->options['render'];
    }

    public function containsHtml(): bool {
        return false;
    }

    public function getRenderedLength(): ?int {
        return null;
    }

    public function isDate(): bool {
        return false;
    }

    public function getMap(): ?array {
        return null;
    }

    public function getNormalizedMap(): ?array {
        return null;
    }

    public function getDefaultValue() {
        return $this->options['defaultValue'];
    }

    public function getType() {
        return $this->options['type'];
    }

    public function getFieldOptions() {
        return $this->options['options'];
    }

    public function isRequired() {
        return $this->options['required'];
    }

    public function isComparable() {
        return $this->options['comparable'];
    }

    public function getFormat() {
        return $this->options['format'];
    }

}
