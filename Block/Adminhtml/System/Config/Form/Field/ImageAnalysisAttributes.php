<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class ImageAnalysisAttributes extends AbstractFieldArray
{
    private const INSTRUCTION_COLUMN = 'instruction';

    /**
     * @var string
     */
    protected $_template = 'Mageprince_MageAI::system/config/form/field/image-analysis-attributes.phtml';

    /**
     * @var AttributeColumn|null
     */
    protected $attributeRenderer;

    /**
     * @var OverwritePolicyColumn|null
     */
    protected $policyRenderer;

    /**
     * @var AllowNewOptionsColumn|null
     */
    protected $allowNewOptionsRenderer;

    /**
     * Prepare dynamic row columns.
     *
     * @return void
     */
    protected function _prepareToRender()
    {
        $this->addColumn('attribute', [
            'label' => __('Attribute'),
            'renderer' => $this->getAttributeRenderer(),
        ]);
        $this->addColumn(self::INSTRUCTION_COLUMN, [
            'label' => __('Prompt Description'),
            'class' => 'required-entry admin__control-textarea mp-mageai-image-analysis-prompt',
            'style' => 'width:100%; min-height:90px;',
        ]);
        $this->addColumn('policy', [
            'label' => __('Update Policy'),
            'renderer' => $this->getPolicyRenderer(),
        ]);
        $this->addColumn('allow_new_options', [
            'label' => __('Create Options'),
            'renderer' => $this->getAllowNewOptionsRenderer(),
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Attribute');
    }

    /**
     * Render array cell for prototypeJS template.
     *
     * @param string $columnName
     * @return string
     * @throws \Exception
     */
    public function renderCellTemplate($columnName)
    {
        if ($columnName !== self::INSTRUCTION_COLUMN) {
            return parent::renderCellTemplate($columnName);
        }

        $columns = $this->getColumns();
        if (empty($columns[$columnName])) {
            throw new \Exception('Wrong column name specified.');
        }

        $column = $columns[$columnName];

        return '<textarea id="' . $this->_getCellInputElementId('<%- _id %>', $columnName) . '"'
            . ' name="' . $this->_getCellInputElementName($columnName) . '"'
            . ' class="' . (isset($column['class']) ? $column['class'] : 'admin__control-textarea') . '"'
            . (isset($column['style']) ? ' style="' . $column['style'] . '"' : '')
            . '><%- ' . $columnName . ' %></textarea>';
    }

    /**
     * Get columns rendered on the compact first row.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getMainColumns(): array
    {
        $columns = $this->getColumns();
        unset($columns[self::INSTRUCTION_COLUMN]);

        return $columns;
    }

    /**
     * Get the prompt/instruction column name.
     *
     * @return string
     */
    public function getInstructionColumnName(): string
    {
        return self::INSTRUCTION_COLUMN;
    }

    /**
     * Get prompt/instruction row label.
     *
     * @return \Magento\Framework\Phrase|string
     */
    public function getInstructionLabel()
    {
        $columns = $this->getColumns();

        return $columns[self::INSTRUCTION_COLUMN]['label'] ?? __('Prompt Description');
    }

    /**
     * Mark selected attribute in each row.
     *
     * @param DataObject $row
     * @return void
     */
    protected function _prepareArrayRow(DataObject $row)
    {
        $attribute = (string) $row->getData('attribute');
        $policy = (string) $row->getData('policy');
        $allowNewOptions = (string) $row->getData('allow_new_options');
        $options = [];

        if ($attribute !== '') {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($attribute)] = 'selected="selected"';
        }
        if ($policy !== '') {
            $options['option_' . $this->getPolicyRenderer()->calcOptionHash($policy)] = 'selected="selected"';
        }
        if ($allowNewOptions !== '') {
            $options['option_' . $this->getAllowNewOptionsRenderer()->calcOptionHash($allowNewOptions)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * Get product attribute select renderer.
     *
     * @return AttributeColumn
     */
    private function getAttributeRenderer(): AttributeColumn
    {
        if ($this->attributeRenderer === null) {
            $this->attributeRenderer = $this->getLayout()->createBlock(
                AttributeColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->attributeRenderer;
    }

    /**
     * Get overwrite policy select renderer.
     *
     * @return OverwritePolicyColumn
     */
    private function getPolicyRenderer(): OverwritePolicyColumn
    {
        if ($this->policyRenderer === null) {
            $this->policyRenderer = $this->getLayout()->createBlock(
                OverwritePolicyColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->policyRenderer;
    }

    /**
     * Get allow-new-options select renderer.
     *
     * @return AllowNewOptionsColumn
     */
    private function getAllowNewOptionsRenderer(): AllowNewOptionsColumn
    {
        if ($this->allowNewOptionsRenderer === null) {
            $this->allowNewOptionsRenderer = $this->getLayout()->createBlock(
                AllowNewOptionsColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->allowNewOptionsRenderer;
    }
}
