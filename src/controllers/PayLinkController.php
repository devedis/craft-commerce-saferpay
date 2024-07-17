<?php

namespace craft\commerce\saferpay\controllers;


use craft\commerce\saferpay\Plugin;
use craft\elements\Entry;
use craft\gql\types\DateTime;
use craft\web\Controller;
use Money\Currency;
use Money\Money;

class PayLinkController extends Controller
{

    public array|bool|int $allowAnonymous = ['payment', 'redirect', 'failed-redirect', 'test'];


    //actions/ft-safe-pay/pay-link/payment
    public function  actionPayment()
    {
        $request = \Craft::$app->getRequest()->getBodyParams();
        $result = Plugin::getInstance()->saferpayService->paymentPageInitializeLink($request);
        if (!isset($result['error'])){
            $_SESSION["Token"] = $result['body']['Token'];
//            dd($_SESSION['Token']);
            return $this->redirect($result['body']['RedirectUrl']);
        }
        return $this->asJson($result);

    }


    //actions/ft-safe-pay/pay-link/failed-redirect
    public function actionFailedRedirect()
    {
        $token = $_SESSION["Token"] ?? '';
        if ($token) {
            $response = Plugin::getInstance()->saferpayService->paymentPageAssert($token);
//            dd($response, $token);
            $hash = \Craft::$app->getRequest()->getRequiredParam('commerceTransactionHash');
            $transaction['hash'] = $hash;
            if (isset($response['Transaction']['Id'])){
                Plugin::getInstance()->saferpayService->transactionCancel($transaction, $response['Transaction']['Id']);
            }
        }
        $global = \Craft::$app->getGlobals()->getSetByHandle('flyingTeachers');
        $failedUrl = $global->failedRedirect->one()?->getUrl();
        return $this->redirect($failedUrl);

    }

    // actions/commerce-saferpay/pay-link/redirect

    public function actionRedirect()
    {
        $emailClient = \Craft::$app->getRequest()->getRequiredParam('email');
        $hash = \Craft::$app->getRequest()->getRequiredParam('commerceTransactionHash');
        $firstName = \Craft::$app->getRequest()->getRequiredParam('firstName');
        $lastName = \Craft::$app->getRequest()->getRequiredParam('lastName');
        $descTitle = \Craft::$app->getRequest()->getRequiredParam('descTitle');
        $global = \Craft::$app->getGlobals()->getSetByHandle('flyingTeachers');
        $token = $_SESSION["Token"] ?? '';
//        dd($token);
        if ($token) {
            $response = Plugin::getInstance()->saferpayService->paymentPageAssert($token);
        }
        $transaction['hash'] = $hash;
        $orderId = $response['Transaction']['OrderId'];
        $fields = \Craft::$app->getRequest()->getRequiredParam('fields');
        $entryId = base64_decode(json_decode($fields)->entryId);
        $entry = \Craft::$app->getEntries()->getEntryById($entryId);
        $titleEntry = $entry?->title;
        $dateString = $response['Transaction']['Date'];
        $dateTime = new \DateTime($dateString);

        $orderDate = $dateTime->format('d.m.Y');
        $orderTime = $dateTime->format('H:i:s');
        $price = $response['Transaction']['Amount']['Value'] / 100 . ' ' . $response['Transaction']['Amount']['CurrencyCode'];
        $courseTitle = '';
        $courseDate = '';
        $courseTime = '';
        $courseNiveau = '';
        $courseLocation = '';
        $courseLanguage = '';
        $parseFields = $this->emailFieldsPrepare($fields);
        $course = null;
        if ($entry){
            $course = $entry->courseOption->one();
            if ($course){
                $courseTitle = $course->title;
                $courseDate = $course->course_dates->one()?->dateStart?->format('d.m.Y');
                $courseTime = $course->course_times->one()?->timeStart?->format('H:i');
                $courseNiveau = $course->course_niveau->one()?->title;
                $courseLocation = $course->course_location->one()?->title;
                $courseLanguage = $course->course_language->one()?->title;
            }
        }
        if (!$response || !isset($response['Transaction']['Status'])) {
            $status = 'unknown';
//            $transactionId = null;
        }else if ($response['Transaction']['Status'] === 'AUTHORIZED') {
            Plugin::getInstance()->saferpayService->transactionCapture($transaction, $response['Transaction']['Id']);
            $status = 'successful';
//            $transactionId = $response['Transaction']['Id'];
        } else {
            Plugin::getInstance()->saferpayService->transactionCancel($transaction, $response['Transaction']['Id']);
            $status = 'processing';
//            $transactionId = $response['Transaction']['Id'];
        }
        $successRedirect = $global->succesRedirect->one()?->getUrl();
        $entry = new Entry();
        $section = \Craft::$app->getSections()->getSectionByHandle('payLinksPayments');
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = $descTitle;
        $entry->adminEmail = $emailClient;
        $entry->user_firstName = $firstName;
        $entry->user_lastName = $lastName;
        $priceForEntry = new Money($response['Transaction']['Amount']['Value'], new Currency($response['Transaction']['Amount']['CurrencyCode']));
        $entry->setFieldValue('price', $priceForEntry->getAmount());
        $entry->courseOption = [$course?->id];
        $entry->payLinkEntry = [$entryId];
        $entry->orderId = $orderId;
        $entry->paymentStatus = $status;
        $entry->payload = $parseFields;
        try {
            \Craft::$app->getElements()->saveElement($entry);
        }catch (\Exception $e){
        }
        $orderUrl = $entry->getCpEditUrl();

        $subject = $global->subjectForClientEmail;
        $subject = str_replace(
            [
                '<email>', '<orderId>', '<firstName>', '<lastName>', '<status>', '<courseTitle>', '<courseDate>',
                '<courseTime>', '<courseNiveau>', '<courseLocation>', '<courseLanguage>', '<parseFields>',
                '<titleEntry>', '<orderDate>', '<price>', '<descTitle>', '<orderTime>', '<orderUrl>'
            ],
            [
                $emailClient, $orderId, $firstName, $lastName, $status,
                $courseTitle, $courseDate, $courseTime, $courseNiveau,
                $courseLocation, $courseLanguage, $parseFields, $titleEntry,
                $orderDate, $price, $descTitle, $orderTime, $orderUrl
            ],
            $subject
        );
        $body = (string)$global->bodyForClientEmail;
        $body = str_replace(
            [
                '&lt;email&gt;', '&lt;orderId&gt;', '&lt;firstName&gt;', '&lt;lastName&gt;', '&lt;status&gt;',
                '&lt;courseTitle&gt;', '&lt;courseDate&gt;', '&lt;courseTime&gt;', '&lt;courseNiveau&gt;',
                '&lt;courseLocation&gt;', '&lt;courseLanguage&gt;', '&lt;parseFields&gt;', '&lt;titleEntry&gt;',
                '&lt;orderDate&gt;', '&lt;price&gt;', '&lt;descTitle&gt;', '&lt;orderTime&gt;', '&lt;orderUrl&gt;'
            ],
            [
                $emailClient, $orderId, $firstName, $lastName, $status,
                $courseTitle, $courseDate, $courseTime, $courseNiveau,
                $courseLocation, $courseLanguage, $parseFields, $titleEntry,
                $orderDate, $price, $descTitle, $orderTime, $orderUrl
            ],
            $body
        );


        try {
            \Craft::$app
                ->getMailer()
                ->compose()
                ->setTo($emailClient)
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->send();
        }catch (\Exception $e){
        }

        $email = $global->adminEmail;
        $subject = $global->subjectForAdminEmail;
        $subject = str_replace(
            [
                '<email>', '<orderId>', '<firstName>', '<lastName>', '<status>', '<courseTitle>', '<courseDate>',
                '<courseTime>', '<courseNiveau>', '<courseLocation>', '<courseLanguage>', '<parseFields>',
                '<titleEntry>', '<orderDate>', '<price>', '<descTitle>', '<orderTime>', '<orderUrl>'
            ],
            [
                $emailClient, $orderId, $firstName, $lastName, $status,
                $courseTitle, $courseDate, $courseTime, $courseNiveau,
                $courseLocation, $courseLanguage, $parseFields, $titleEntry,
                $orderDate, $price, $descTitle, $orderTime, $orderUrl
            ],
            $subject
        );
        $body = (string)$global->bodyForAdminEmail;
        $body = str_replace(
            [
                '&lt;email&gt;', '&lt;orderId&gt;', '&lt;firstName&gt;', '&lt;lastName&gt;', '&lt;status&gt;',
                '&lt;courseTitle&gt;', '&lt;courseDate&gt;', '&lt;courseTime&gt;', '&lt;courseNiveau&gt;',
                '&lt;courseLocation&gt;', '&lt;courseLanguage&gt;', '&lt;parseFields&gt;', '&lt;titleEntry&gt;',
                '&lt;orderDate&gt;', '&lt;price&gt;', '&lt;descTitle&gt;', '&lt;orderTime&gt;', '&lt;orderUrl&gt;'
            ],
            [
                $emailClient, $orderId, $firstName, $lastName, $status,
                $courseTitle, $courseDate, $courseTime, $courseNiveau,
                $courseLocation, $courseLanguage, $parseFields, $titleEntry,
                $orderDate, $price, $descTitle, $orderTime, $orderUrl
            ],
            $body
        );
        try {
            \Craft::$app
                ->getMailer()
                ->compose()
                ->setTo($email)
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->send();
        }catch (\Exception $e){
        }
        return $this->redirect($successRedirect);
    }

    public function emailFieldsPrepare($json)
    {
//        $json = '{"CRAFT_CSRF_TOKEN":"ENH0xxRwOlgUnNugGAf_Z3Ib6O-LJWNLXRoWewTunz_nLA74Zs6nwH597VZSa5A46hooXT1tKcstkdRWtdqS4yUUw2ymU--zZ_DtZ1VEq3wRKxHIPN5dq5CDSiqsz6BYKZnnsnwyC0VzxqNTnqalNYxJ8wHvAWEMk1TJA9VawXTTov0Wz09Y4Ibhsl7jbs9UrgKTKHuBtqNWBU4Lf9O0_yl9uQ0cRLiNyBxXHSQuZg9yic9mpkhIsCyX94wCG9xuZFmgDtktSWwIDEz7TqTtMNa799QXLKZYxGOMhgSViAJjIMoaIBMm-l27Ps6j4HMdzvzBahH8hYJPATMnQq3zEfrk0EHfIrxusDAbSvk6llO3GfhAhdvJZrs5P7DfoNYYqySWBOJ-oRo=","action":"commerce-saferpay\/pay-link\/payment","billingAddressSameAsShipping":"1","entryId":"ODg3NzYz","email":"stephan@blank-studio.de","shippingAddress":{"countryCode":"DE","administrativeArea":"0","isPrimaryBilling":"1","isPrimaryShipping":"1","fullName":"stephan wergles","fields":{"user_salutation":"Mr.","user_firstName":"stephan","user_lastName":"wergles","user_birthday":"","user_phone":"089","user_country":"GE","privacy":"Privacy accepted"},"organization":"","addressLine1":"tal","addressLine2":"","locality":"muc","postalCode":"80000"}}';
        $jsonDecoded = (array)json_decode($json);
        unset($jsonDecoded['CRAFT_CSRF_TOKEN']);
        unset($jsonDecoded['action']);
        unset($jsonDecoded['billingAddressSameAsShipping']);
        unset($jsonDecoded['billingAddressSameAsShipping']);
        unset($jsonDecoded['entryId']);
        $shAd = (array)$jsonDecoded['shippingAddress'];
        $html = "Email: ". $jsonDecoded['email'] . '<br>';
        $html .= "Shipping Address: <br>";
        $html .= "Country Code: ". $shAd['countryCode'] ."<br>";
        $html .= "Administrative Area: ". $shAd['administrativeArea'] ."<br>";
        $html .= "Full Name: ". $shAd['fullName'] ."<br>";
        $html .= "Organization: ". $shAd['organization'] ."<br>";
        $html .= "Address Line 1: ". $shAd['addressLine1'] ."<br>";
        $html .= "Address Line 2: ". $shAd['addressLine2'] ."<br>";
        $html .= "Locality: ". $shAd['locality'] ."<br>";
        $html .= "Postal Code: ". $shAd['postalCode'] ."<br>";
        $html .= "Salutation: ". $shAd['fields']->user_salutation ."<br>";
        $html .= "First Name: ". $shAd['fields']->user_firstName ."<br>";
        $html .= "Last Name: ". $shAd['fields']->user_lastName ."<br>";
        $html .= "Birthday: ". $shAd['fields']->user_birthday ."<br>";
        $html .= "Phone: ". $shAd['fields']->user_phone ."<br>";
        $html .= "Country: ". $shAd['fields']->user_country ."<br>";
        $html .= "Privacy: ". $shAd['fields']->privacy ."<br>";
        return $html;

    }


}
