<?php
/**
 * Copyright © MercadoPago. All rights reserved.
 *
 * @author      Mercado Pago
 * @license     See LICENSE for license details.
 */

namespace MercadoPago\AdbPayment\Model\Adminhtml\Source;

use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class PaymentGroup - Fieldset renderer for Mercado Pago.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 */
class PaymentGroup extends Fieldset
{
    /**
     * Return header comment part of html for fieldset.
     *
     * @param AbstractElement $element
     *
     * @return string
     */
    protected function _getHeaderCommentHtml($element)
    {
        $groupConfig = $element->getGroup();

        if (empty($groupConfig['help_url']) || !$element->getComment()) {
            return parent::_getHeaderCommentHtml($element);
        }

        $html = '<div class="comment">'.
            $element->getComment().
            ' <a target="_blank" href="'.$groupConfig['help_url'].'">'.
            __('Configure').'</a></div>';

        return $html;
    }

    /**
     * Return collapse state.
     *
     * @param AbstractElement $element
     *
     * @return bool
     */
    protected function _isCollapseState($element)
    {
        $extra = $this->_authSession->getUser()->getExtra();
        if (isset($extra['configState'][$element->getId()])) {
            return $extra['configState'][$element->getId()];
        }

        $groupConfig = $element->getGroup();
        if (!empty($groupConfig['expanded'])) {
            return true;
        }

        return false;
    }
}
