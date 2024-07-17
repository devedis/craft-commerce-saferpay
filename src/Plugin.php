<?php

namespace craft\commerce\saferpay;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\saferpay\behaviours\SaferpayBehaviour;
use craft\commerce\saferpay\gateways\SaferpayGateway;
use craft\commerce\saferpay\models\Settings;
use craft\commerce\services\Gateways;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\log\MonologTarget;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;

class Plugin extends \craft\base\Plugin
{
    public function init()
    {
        parent::init();
        $this->_registerLogTarget();

        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
            new SaferpayGateway();
        });
    }

    /**
     * Logs an informational message to our custom log target.
     */
    public static function info(string $message): void
    {
        Craft::info($message, 'commerce-saferpay');
    }

    /**
     * Logs an error message to our custom log target.
     */
    public static function error(string $message): void
    {
        Craft::error($message, 'commerce-saferpay');
    }

    private function _registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'commerce-saferpay',
            'categories' => ['commerce-saferpay'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $e) {
            if (is_dir($baseDir = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates')) {
                $e->roots[$this->id] = $baseDir;
            }
        });
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_DEFINE_BEHAVIORS,
            function (DefineBehaviorsEvent $event) {
                $event->sender->attachBehaviors([
                    SaferpayBehaviour::class,
                ]);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            function (Event $event) {
                $order = $event->sender;
                $status = $order->getLastTransaction()->status;

                if ($status === 'processing') {
                    $this->updateOrderStatus($order, 'pending');
                }

                if ($status === 'success') {
                    $this->updateOrderStatus($order, 'done');
                }
            }
        );

        Event::on(
            Gateways::class,
            Gateways::EVENT_REGISTER_GATEWAY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SaferpayGateway::class;
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function (Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $address = $order->shippingAddress;
                $customer = $order->customer;

                if ($address and $customer and $primary = $customer->getPrimaryShippingAddress()) {
                    $primary = Craft::$app->getElements()->getElementById($primary->id);
                    if (!$primary) {
                        return;
                    }
                    $primary->countryCode = $address->countryCode;
                    $primary->locality = $address->locality;
                    $primary->postalCode = $address->postalCode;
                    $primary->dependentLocality = $address->dependentLocality;
                    $primary->postalCode = $address->postalCode;
                    $primary->sortingCode = $address->sortingCode;
                    $primary->addressLine1 = $address->addressLine1;
                    $primary->addressLine2 = $address->addressLine2;
                    $primary->organization = $address->organization;
                    $primary->organizationTaxId = $address->organizationTaxId;
                    $primary->latitude = $address->latitude;
                    $primary->longitude = $address->longitude;
                    $primary->fullName = $address->fullName;
                    $primary->user_firstName = $address->user_firstName;
                    $primary->user_lastName = $address->user_lastName;
                    $primary->user_salutation = $address->user_salutation;
                    $primary->user_birthday = $address->user_birthday;
                    $primary->user_country = $address->user_country;
                    $primary->user_phone = $address->user_phone;
                    return Craft::$app->getElements()->saveElement($primary);
                }
                $user = \Craft::$app->getUsers()->getUserById($order->customer->id);
                $res = \Craft::$app->getElements()->duplicateElement($order->shippingAddress, ['ownerId' => $order->customer->id]);
                $customersService = Commerce::getInstance()->getCustomers();
                $customersService->savePrimaryShippingAddressId($user, $res->id);
                $customersService->savePrimaryBillingAddressId($user, $res->id);
                \Craft::$app->elements->saveElement($user);
            }
        );
    }

    private function updateOrderStatus(Order $order, $newStatusHandle)
    {
        $newStatus = Commerce::getInstance()->orderStatuses->getOrderStatusByHandle($newStatusHandle);

        if (!$newStatus) {
            return 'Invalid order status handle.';
        }

        $order->orderStatusId = $newStatus->id;
        return Craft::$app->elements->saveElement($order);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

}
