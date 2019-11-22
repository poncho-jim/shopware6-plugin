<?php declare(strict_types=1);

namespace PaynlPayment\Helper;

use Exception;
use Paynl\Result\Transaction\Transaction as ResultTransaction;
use PaynlPayment\Components\Api;
use PaynlPayment\Entity\PaynlTransactionEntity;
use PaynlPayment\Entity\PaynlTransactionEntity as PaynlTransaction;
use PaynlPayment\Entity\PaynlTransactionEntityDefinition;
use phpDocumentor\Reflection\Types\Mixed;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ProcessingHelper
{
    /** @var Api */
    private $paynlApi;
    /** @var EntityRepositoryInterface */
    private $paynlTransactionRepository;
    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

    public function __construct(
        Api $api,
        EntityRepositoryInterface $paynlTransactionRepository,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->paynlApi = $api;
        $this->paynlTransactionRepository = $paynlTransactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
    }

    public function storePaynlTransactionData(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        string $paynlTransactionId,
        ?\Throwable $exception = null
    ): void {
        $shopwarePaymentMethodId = $salesChannelContext->getPaymentMethod()->getId();
        /** @var CustomerEntity $customer */
        $customer = $salesChannelContext->getCustomer();
        $transactionData = [
            'paynlTransactionId' => $paynlTransactionId,
            'customerId' => $customer->getId(),
            'orderId' => $transaction->getOrder()->getId(),
            'orderTransactionId' => $transaction->getOrderTransaction()->getId(),
            'paymentId' => $this->paynlApi->getPaynlPaymentMethodId($shopwarePaymentMethodId),
            'amount' => $transaction->getOrder()->getAmountTotal(),
            'currency' => $salesChannelContext->getCurrency()->getIsoCode(),
            // TODO: check sComment from shopware5 plugin
            'dispatch' => $salesChannelContext->getShippingMethod()->getId(),
            'exception' => (string)$exception,
        ];
        $this->paynlTransactionRepository->create([$transactionData], $salesChannelContext->getContext());
    }

    /**
     * @return mixed
     */
    public function findTransactionByOrderId(string $orderId, Context $context)
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('orderId', $orderId));
        return $this->paynlTransactionRepository->search($criteria, $context)->first();
    }

    public function getApiTransaction(string $transactionId): ResultTransaction
    {
        return $this->paynlApi->getTransaction($transactionId);
    }

    /**
     * @param PaynlTransactionEntity $paynlTransaction
     * @param Context $context
     * @param bool $isExchange
     * @return string
     */
    public function updateTransaction(PaynlTransaction $paynlTransaction, Context $context, bool $isExchange): string
    {
        try {
            $apiTransaction = $this->getApiTransaction($paynlTransaction->getPaynlTransactionId());
            $paynlTransactionId = $paynlTransaction->getId();
            $status = 0;
            if ($apiTransaction->isBeingVerified()) {
                $status = PaynlTransactionEntityDefinition::STATUS_PENDING;
            } elseif ($apiTransaction->isPending()) {
                $status = PaynlTransactionEntityDefinition::STATUS_PENDING;
            } elseif ($apiTransaction->isRefunded()) {
                $status = PaynlTransactionEntityDefinition::STATUS_REFUND;
                $this->transactionStateHandler->refund($paynlTransaction->get('orderTransactionId'), $context);
            } elseif ($apiTransaction->isPartiallyRefunded()) {
                $status = PaynlTransactionEntityDefinition::STATUS_REFUND;
                $this->transactionStateHandler->refundPartially($paynlTransaction->get('orderTransactionId'), $context);
            } elseif ($apiTransaction->isAuthorized()) {
                $status = PaynlTransactionEntityDefinition::STATUS_AUTHORIZED;
            } elseif ($apiTransaction->isPaid()) {
                $status = PaynlTransactionEntityDefinition::STATUS_PAID;
                $this->transactionStateHandler->pay($paynlTransaction->get('orderTransactionId'), $context);
            } elseif ($apiTransaction->isCanceled()) {
                $status = $status = PaynlTransactionEntityDefinition::STATUS_CANCEL;
                $this->transactionStateHandler->cancel($paynlTransaction->get('orderTransactionId'), $context);
            }

            $this->setPaynlStatus($paynlTransactionId, $context, $status);
            $apiTransactionData = $apiTransaction->getData();

            return sprintf(
                "Status updated to: %s (%s) orderNumber: %s",
                $apiTransactionData['paymentDetails']['stateName'],
                $apiTransactionData['paymentDetails']['state'],
                $apiTransactionData['paymentDetails']['orderNumber']
            );
        } catch (Exception $e) {
            if ($isExchange && $e->getCode() == 999) {
                return $e->getMessage();
            }
        }
        return "No action, order was not created";
    }

    /**
     * @param string $paynlTransactionId
     * @param Context $context
     * @param int $status
     */
    public function setPaynlStatus(string $paynlTransactionId, Context $context, int $status): void
    {
        $this->paynlTransactionRepository->update(
            [
                [
                    'id' =>$paynlTransactionId,
                    'stateId' =>$status,
                ]
            ],
            $context
        );
    }

    /**
     * @param string $paynlTransactionId
     * @return mixed
     */
    public function processNotify(string $paynlTransactionId)
    {
        $apiTransaction = $this->getApiTransaction($paynlTransactionId);
        if ($apiTransaction->isPending()) {
            return;
        }
        $criteria = (new Criteria());
        $context = Context::createDefaultContext();
        $criteria->addFilter(new EqualsFilter('paynlTransactionId', $paynlTransactionId));
        $entity = $this->paynlTransactionRepository->search($criteria, $context)->first();

        return $this->updateTransaction($entity, $context, true);
    }
}
