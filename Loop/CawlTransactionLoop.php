<?php

declare(strict_types=1);

namespace CawlPayment\Loop;

use CawlPayment\Model\CawlTransactionQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display CAWL transactions
 *
 * Usage:
 * {loop type="cawl_transaction" name="my_loop" order_id="123"}
 *   {$ID} - {$STATUS} - {$AMOUNT} {$CURRENCY}
 * {/loop}
 */
class CawlTransactionLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createIntTypeArgument('order_id'),
            Argument::createAnyTypeArgument('status'),
            Argument::createAnyTypeArgument('order', 'created_at-desc')
        );
    }

    public function buildModelCriteria(): ModelCriteria
    {
        $query = CawlTransactionQuery::create();

        if ($id = $this->getId()) {
            $query->filterById($id);
        }

        if ($orderId = $this->getOrderId()) {
            $query->filterByOrderId($orderId);
        }

        if ($status = $this->getStatus()) {
            $query->filterByStatus($status);
        }

        // Handle ordering
        $orderBy = $this->getOrder();
        if ($orderBy) {
            $parts = explode('-', $orderBy);
            $column = $parts[0] ?? 'created_at';
            $direction = ($parts[1] ?? 'desc') === 'asc' ? Criteria::ASC : Criteria::DESC;

            $columnMap = [
                'id' => 'Id',
                'created_at' => 'CreatedAt',
                'updated_at' => 'UpdatedAt',
                'status' => 'Status',
                'amount' => 'Amount',
            ];

            $propelColumn = $columnMap[$column] ?? 'CreatedAt';
            $query->orderBy($propelColumn, $direction);
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $transaction) {
            $loopResultRow = new LoopResultRow($transaction);

            $loopResultRow
                ->set('ID', $transaction->getId())
                ->set('ORDER_ID', $transaction->getOrderId())
                ->set('PAYMENT_METHOD', $transaction->getPaymentMethod())
                ->set('HOSTED_CHECKOUT_ID', $transaction->getHostedCheckoutId())
                ->set('TRANSACTION_REF', $transaction->getTransactionRef())
                ->set('AMOUNT', $transaction->getAmount())
                ->set('CURRENCY', $transaction->getCurrency())
                ->set('STATUS', $transaction->getStatus())
                ->set('STATUS_CODE', $transaction->getStatusCode())
                ->set('ERROR_MESSAGE', $transaction->getErrorMessage())
                ->set('CREATED_AT', $transaction->getCreatedAt())
                ->set('UPDATED_AT', $transaction->getUpdatedAt());

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }
}
