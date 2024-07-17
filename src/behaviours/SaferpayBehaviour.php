<?php

namespace craft\commerce\saferpay\behaviours;

use Craft;
use yii\base\Behavior;

class SaferpayBehaviour extends Behavior
{
    public function safepay(): string
    {
        return Craft::$app->getView()->renderTemplate('commerce-saferpay/form.twig');
    }
}
