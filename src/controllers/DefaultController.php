<?php

namespace craft\commerce\saferpay\controllers;

use Craft;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\web\Controller as BaseController;
use yii\web\Response;

class DefaultController extends BaseController
{
    protected array|bool|int $allowAnonymous = ['check-payment'];

    /*
     * Used for checking the payment status of a transaction when used in headless mode
     */
    public function actionCheckPayment(): Response
    {
        $request = Craft::$app->getRequest();
        $commerceTransactionHash = $request->getParam('commerceTransactionHash');

        $transaction = CommercePlugin::getInstance()->getTransactions()->getTransactionByHash($commerceTransactionHash);
        $isSuccessful = CommercePlugin::getInstance()->getTransactions()->isTransactionSuccessful($transaction);

        if ($isSuccessful) {
            return $this->asSuccess(data: ['status' => TransactionRecord::STATUS_SUCCESS]);
        }

        $childTransactions = $transaction->getChildTransactions();
        $hasAnyChildFailed = in_array(true, array_map(fn($childTransaction) => $childTransaction->status === TransactionRecord::STATUS_FAILED, $childTransactions));

        return $this->asSuccess(data: ['status' => $hasAnyChildFailed ? TransactionRecord::STATUS_FAILED : TransactionRecord::STATUS_PROCESSING]);
    }
}
