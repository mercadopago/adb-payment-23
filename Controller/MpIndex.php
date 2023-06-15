<?php
/**
 * Copyright © MercadoPago. All rights reserved.
 *
 * @author      Mercado Pago
 * @license     See LICENSE for license details.
 */

namespace MercadoPago\AdbPayment\Controller;

use Exception;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Notification\NotifierInterface as NotifierPool;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Result\PageFactory;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Service\CreditmemoService;
use MercadoPago\AdbPayment\Gateway\Config\Config;
use MercadoPago\AdbPayment\Model\Console\Command\Notification\CheckoutProAddChildPayment;
use MercadoPago\AdbPayment\Model\Console\Command\Notification\FetchStatus;
use Magento\Sales\Model\Order\Payment\Transaction;
use MercadoPago\AdbPayment\Model\MPApi\Notification;

/**
 * Class Mercado Pago Index.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class MpIndex extends Action
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteria;

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transaction;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var PageFactory
     */
    protected $pageFactory;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var FetchStatus
     */
    protected $fetchStatus;

    /**
     * @var NotifierPool
     */
    protected $notifierPool;

    /**
     * @var CreditmemoFactory
     */
    protected $creditMemoFactory;

    /**
     * @var CreditmemoService
     */
    protected $creditMemoService;

    /**
     * @var Invoice
     */
    protected $invoice;

    /**
     * @var CheckoutProAddChildPayment
     */
    protected $addChildPayment;

    /**
     * @var Notification
     */
    protected $mpApiNotification;

    /**
     * @param Config                         $config
     * @param Context                        $context
     * @param Json                           $json
     * @param SearchCriteriaBuilder          $searchCriteria
     * @param TransactionRepositoryInterface $transaction
     * @param OrderRepository                $orderRepository
     * @param PageFactory                    $pageFactory
     * @param JsonFactory                    $resultJsonFactory
     * @param Logger                         $logger
     * @param FetchStatus                    $fetchStatus
     * @param NotifierPool                   $notifierPool
     * @param CreditmemoFactory              $creditMemoFactory
     * @param CreditmemoService              $creditMemoService
     * @param Invoice                        $invoice
     * @param CheckoutProAddChildPayment     $addChildPayment
     * @param Notification                   $mpApiNotification
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Config $config,
        Context $context,
        Json $json,
        SearchCriteriaBuilder $searchCriteria,
        TransactionRepositoryInterface $transaction,
        OrderRepository $orderRepository,
        PageFactory $pageFactory,
        JsonFactory $resultJsonFactory,
        Logger $logger,
        FetchStatus $fetchStatus,
        NotifierPool $notifierPool,
        CreditmemoFactory $creditMemoFactory,
        CreditmemoService $creditMemoService,
        Invoice $invoice,
        CheckoutProAddChildPayment $addChildPayment,
        Notification $mpApiNotification
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->json = $json;
        $this->searchCriteria = $searchCriteria;
        $this->transaction = $transaction;
        $this->orderRepository = $orderRepository;
        $this->pageFactory = $pageFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->fetchStatus = $fetchStatus;
        $this->notifierPool = $notifierPool;
        $this->creditMemoFactory = $creditMemoFactory;
        $this->creditMemoService = $creditMemoService;
        $this->invoice = $invoice;
        $this->addChildPayment = $addChildPayment;
        $this->mpApiNotification = $mpApiNotification;
    }

    /**
     * Get Order Data.
     *
     * @param string $orderId
     *
     * @return OrderRepository|ResultInterface
     */
    public function getOrderData($orderId)
    {
        try {
            /** @var OrderRepository $order */
            $order = $this->orderRepository->get($orderId);
        } catch (Exception $exc) {
            return $this->createResult(
                500,
                [
                    'error'   => 500,
                    'message' => $exc->getMessage(),
                ]
            );
        }

        return $order;
    }

    /**
     * Create Result.
     *
     * @param int   $statusCode
     * @param array $data
     *
     * @return ResultInterface
     */
    public function createResult($statusCode, $data)
    {
        /** @var JsonFactory $resultPage */
        $resultPage = $this->resultJsonFactory->create();
        $resultPage->setHttpResponseCode($statusCode);
        $resultPage->setData($data);

        return $resultPage;
    }

    /**
     * Filter Invalid Notification.
     *
     * @param string          $mpStatus
     * @param OrderRepository $order
     * @param string|null     $mpAmountRefound
     *
     * @return array
     */
    public function filterInvalidNotification(
        $mpStatus,
        $order,
        $refundId,
        $mpAmountRefound = null,
        $origin = null
    ) {
        $result = [];

        if (!$order->getEntityId()) {
            $result = [
                'isInvalid' => true,
                'code'      => 406,
                'msg'       => __('Order not found.'),
            ];

            return $result;
        }

        if ($mpStatus === 'refunded') {
            if (isset($origin) && $origin === 'magento') {
                $result = [
                    'isInvalid' => true,
                    'code'      => 200,
                    'msg'       => 'Notification response for online refund created in magento',
                ];
                return $result;
            }
            if ($order->getState() !== \Magento\Sales\Model\Order::STATE_CLOSED) {
                $storeId = $order->getStoreId();
                $applyRefund = $this->config->isApplyRefund($storeId);

                if ($applyRefund) {
                    $result = $this->refund($order, $refundId, $mpAmountRefound);

                    $header = __('Mercado Pago, refund notification');

                    $description = $result['description'];

                    $this->notifierPool->addCritical($header, $description);

                    return $result;
                }

                $result = [
                    'isInvalid' => true,
                    'code'      => 200,
                    'msg'       => __('Unable to apply refund.'),
                ];

                return $result;
            }

            if ($order->getState() === \Magento\Sales\Model\Order::STATE_CLOSED) {{
                $header = __('Mercado Pago, refund notification');

                $description = __(
                    'Invalid notification. The order %1 has already been closed.',
                    $order->getIncrementId()
                );

                $this->notifierPool->addCritical($header, $description);

                $result = [
                'isInvalid' => true,
                'code'      => 200,
                'msg'       => 'Refund notification for order already closed.',
                ];

                return $result;
            }}

            $result = [
                'isInvalid' => true,
                'code'      => 412,
                'msg'       => __('Unavailable.'),
            ];

            return $result;
        }

        if ($order->getState() === \Magento\Sales\Model\Order::STATE_CLOSED) {
            $result = [
                'isInvalid' => true,
                'code'      => 412,
                'msg'       => [
                    'error'   => 412,
                    'message' => __('Unavailable.'),
                    'state'   => $order->getState(),
                ],
            ];

            return $result;
        }

        $result = [
            'isInvalid' => false,
        ];

        return $result;
    }

    /**
     * Checkout Pro Add Child Information.
     *
     * @param int    $orderId
     * @param string $childId
     *
     * @return void
     */
    public function checkoutProAddChildInformation(
        $orderId,
        $childId
    ) {
        $this->addChildPayment->add($orderId, $childId);
    }

    /**
     * Refund Transction.
     *
     * @param OrderInterface $order
     * @param string|null    $mpAmountRefound
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @return array|null
     */
    public function refund(
        OrderInterface $order,
        $refundId,
        $mpAmountRefound = null
    ) {
        $invoices = $order->getInvoiceCollection();

        if (count($invoices) == 0) {
            return;
        }

        foreach ($invoices as $invoice) {
            $invoice = $this->invoice->loadByIncrementId($invoice->getIncrementId());
            $creditMemo = $this->creditMemoFactory->createByOrder($order);

            $payment = $order->getPayment();
            $payment->setTransactionId($refundId);
            $payment->setIsTransactionClosed(true);

            if ($mpAmountRefound < $creditMemo->getBaseGrandTotal()) {
                $creditMemo->setItems([]);
            }

            $payment->addTransaction(Transaction::TYPE_REFUND);
            $order->save();

            $creditMemo->setState(1);
            $creditMemo->setBaseGrandTotal($mpAmountRefound);
            $creditMemo->setGrandTotal($mpAmountRefound);
            $creditMemo->addComment(__('Order refunded in Mercado Pago, refunded offline in the store.'));

            try {
                $this->creditMemoService->refund($creditMemo, false);
                $result = [
                    'isInvalid'     => true,
                    'code'          => 200,
                    'msg'           => 'Refund notification for order refunded directly in Mercado Pago.',
                    'description'   => __('The order %1, was refunded directly on Mercado Pago, you need to check stock of sold items.',
                    $order->getIncrementId()),
                ];
            } catch (Exception $exc) {
                $result = [
                    'isInvalid'     => true,
                    'code'          => 200,
                    'msg'           => 'Failed to process refund notification.',
                    'description'   => __('The order %1, was refunded directly on Mercado Pago, but an error occured when refunding offline, you need to check order refunds and stock of sold items.',
                    $order->getIncrementId()),
                ];
            }
            $order->addCommentToStatusHistory(__('Order refunded.'));

            return $result;
        }
    }

    protected function loadNotificationData(): array
    {
        $response = $this->getRequest()->getContent();
        $mercadopagoData = $this->json->unserialize($response);

        $this->logger->debug([
            'action'    => 'checkout_custom',
            'payload'   => $response
        ]);

        $storeId = isset($mercadopagoData["payments_metadata"]["store_id"]) ? $mercadopagoData["payments_metadata"]["store_id"] : 1;
        $notificationId = $mercadopagoData['notification_id'];

        return $this->mpApiNotification->get($notificationId, $storeId);
    }
}

