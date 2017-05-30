<?php

namespace craft\commerce\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\Email;
use craft\commerce\models\OrderHistory;
use craft\commerce\Plugin;
use craft\commerce\records\Email as EmailRecord;
use craft\mail\Message;
use yii\base\Component;

/**
 * Email service.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.services
 * @since     1.0
 */
class Emails extends Component
{
    /**
     * @param int $id
     *
     * @return Email|null
     */
    public function getEmailById($id)
    {
        $result = EmailRecord::findOne($id);

        if ($result) {
            return new Email($result);
        }

        return null;
    }

    /**
     *
     * @return Email[]
     */
    public function getAllEmails()
    {
        $records = EmailRecord::find()->orderBy('name')->all();

        return Email::populateModels($records);
    }

    /**
     * @param Email $model
     *
     * @return bool
     * @throws Exception
     * @throws \CDbException
     * @throws \Exception
     */
    public function saveEmail(Email $model)
    {
        if ($model->id) {
            $record = EmailRecord::findOne($model->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce', 'commerce', 'No email exists with the ID “{id}”', ['id' => $model->id]));
            }
        } else {
            $record = new EmailRecord();
        }

        $record->name = $model->name;
        $record->subject = $model->subject;
        $record->recipientType = $model->recipientType;
        $record->to = $model->to;
        $record->bcc = $model->bcc;
        $record->enabled = $model->enabled;
        $record->templatePath = $model->templatePath;

        $record->validate();
        $model->addErrors($record->getErrors());

        if (!$model->hasErrors()) {
            // Save it!
            $record->save(false);

            // Now that we have a record ID, save it on the model
            $model->id = $record->id;

            return true;
        }

        return false;
    }

    /**
     * @param int $id
     *
     * @throws \CDbException
     */
    public function deleteEmailById($id)
    {
        $email = EmailRecord::findOne($id);

        if ($email) {
            return $email->delete();
        }
    }

    /**
     * Sends a commerce email
     *
     * @param Email        $email
     * @param Order        $order
     * @param OrderHistory $orderHistory
     */
    public function sendEmail($email, $order, $orderHistory)
    {

        if (!$email->enabled) {
            return;
        }

        // Set Craft to the site template mode
        $templatesService = Craft::$app->getView();
        $oldTemplateMode = $templatesService->getTemplateMode();
        $templatesService->setTemplateMode(TemplateMode::Site);

        //sending emails
        $renderVariables = [
            'order' => $order,
            'update' => $orderHistory, // TODO: Remove and deprecate 'update' variable in 2.0
            'orderHistory' => $orderHistory
        ];

        $newEmail = new Message();

        $originalLanguage = Craft::$app->language;

        if (Plugin::getInstance()->getSettings()->getSettings()->emailSenderAddress) {
            $newEmail->setFrom(Plugin::getInstance()->getSettings()->getSettings()->emailSenderAddress);
        }

        if ($email->recipientType == EmailRecord::TYPE_CUSTOMER) {
            // use the order's language for template rendering the email fields and body.
            $orderLanguage = $order->orderLocale ?: $originalLanguage;
            Craft::$app->language = $orderLanguage;

            if ($order->getCustomer()) {
                $newEmail->setTo($order->getCustomer()->email);
            } else {
                $newEmail->setTo($order->email);
            }
        }

        if ($email->recipientType == EmailRecord::TYPE_CUSTOM) {
            // To:
            try {
                $newEmail->setTo($templatesService->renderString($email->to, $renderVariables));
            } catch (\Exception $e) {
                $error = Craft::t('commerce', 'Email template parse error for custom email “{email}” in “To:”. Order: “{order}”. Template error: “{message}”', [
                    'email' => $email->name,
                    'order' => $order->getShortNumber(),
                    'message' => $e->getMessage()
                ]);
                Craft::error($error, __METHOD__);

                Craft::$app->language = $originalLanguage;
                $templatesService->setTemplateMode($oldTemplateMode);

                return;
            }
        }

        if (empty($newEmail->toEmail)) {
            $error = Craft::t('commerce', 'Email error. No email address found for order. Order: “{order}”', ['order' => $order->getShortNumber()]);
            Craft::error($error, __METHOD__);

            Craft::$app->language = $originalLanguage;
            $templatesService->setTemplateMode($oldTemplateMode);

            return;
        }

        // BCC:
        try {
            $bcc = $templatesService->renderString($email->bcc, $renderVariables);
            $bcc = str_replace(';', ',', $bcc);
            $bcc = explode(',', $bcc);
            $bccEmails = [];
            foreach ($bcc as $bccEmail) {
                $bccEmails[] = ['email' => $bccEmail];
            }
            $newEmail->setBcc($bccEmails);
        } catch (\Exception $e) {
            $error = Craft::t('commerce', 'commerce', 'Email template parse error for email “{email}” in “BCC:”. Order: “{order}”. Template error: “{message}”', [
                'email' => $email->name,
                'order' => $order->getShortNumber(),
                'message' => $e->getMessage()
            ]);
            Craft::error($error, __METHOD__);

            Craft::$app->language = $originalLanguage;
            $templatesService->setTemplateMode($oldTemplateMode);

            return;
        }

        // Subject:
        try {
            $newEmail->setSubject($templatesService->renderString($email->subject, $renderVariables));
        } catch (\Exception $e) {
            $error = Craft::t('commerce', 'Email template parse error for email “{email}” in “Subject:”. Order: “{order}”. Template error: “{message}”', [
                'email' => $email->name,
                'order' => $order->getShortNumber(),
                'message' => $e->getMessage()
            ]);
            Craft::error($error, __METHOD__);

            Craft::$app->language = $originalLanguage;
            $templatesService->setTemplateMode($oldTemplateMode);

            return;
        }

        // Template Path
        try {
            $templatePath = $templatesService->renderString($email->templatePath, $renderVariables);
        } catch (\Exception $e) {
            $error = Craft::t('commerce', 'Email template path parse error for email “{email}” in “Template Path”. Order: “{order}”. Template error: “{message}”', [
                'email' => $email->name,
                'order' => $order->getShortNumber(),
                'message' => $e->getMessage()
            ]);
            Craft::error($error, __METHOD__);

            Craft::$app->language = $originalLanguage;
            $templatesService->setTemplateMode($oldTemplateMode);

            return;
        }

        // Email Body
        if (!$templatesService->doesTemplateExist($templatePath)) {
            $error = Craft::t('commerce', 'Email template does not exist at “{templatePath}” which resulted in “{templateParsedPath}” for email “{email}”. Order: “{order}”.', [
                'templatePath' => $email->templatePath,
                'templateParsedPath' => $templatePath,
                'email' => $email->name,
                'order' => $order->getShortNumber()
            ]);
            Craft::error($error, __METHOD__);

            Craft::$app->language = $originalLanguage;
            $templatesService->setTemplateMode($oldTemplateMode);

            return;
        }

        try {

            $body = $templatesService->render($templatePath, $renderVariables);
            $newEmail->setHtmlBody($body);
            $newEmail->setTextBody($body);
        } catch (\Exception $e) {
            $error = Craft::t('commerce', 'Email template parse error for email “{email}”. Order: “{order}”. Template error: “{message}”', [
                'email' => $email->name,
                'order' => $order->getShortNumber(),
                'message' => $e->getMessage()
            ]);
            Craft::error($error, __METHOD__);

            Craft::$app->language = $originalLanguage;
            $templatesService->setTemplateMode($oldTemplateMode);

            return;
        }

        Craft::$app->getPlugins()->callFirst('commerce_modifyEmail', [&$newEmail, $order]);

        try {
            //raising event
            $event = new Event($this, [
                'craftEmail' => $newEmail,
                'commerceEmail' => $email,
                'order' => $order,
                'orderHistory' => $orderHistory
            ]);
            $this->onBeforeSendEmail($event);

            if ($event->performAction == false) {
                $error = Craft::t('commerce', 'Email “{email}”, for order "{order}" was cancelled by plugin.', [
                    'email' => $email->name,
                    'order' => $order->getShortNumber()
                ]);

                Craft::error($error, __METHOD__);

                return;
            }

            if (!Craft::$app->getMailer()->send($newEmail)) {
                $error = Craft::t('commerce', 'Email “{email}” could not be sent for order “{order}”. Errors: {errors}', [
                    'errors' => implode(", ", $email->getAllErrors()),
                    'email' => $email->name,
                    'order' => $order->getShortNumber()
                ]);

                Craft::error($error, __METHOD__);
            } else {
                //raising event
                $event = new Event($this, [
                    'craftEmail' => $newEmail,
                    'commerceEmail' => $email,
                    'order' => $order,
                    'orderHistory' => $orderHistory
                ]);
                $this->onSendEmail($event);
            }
        } catch (\Exception $e) {
            $error = Craft::t('commerce', 'Email “{email}” could not be sent for order “{order}”. Error: {error}', [
                'error' => $e->getMessage(),
                'email' => $email->name,
                'order' => $order->getShortNumber()
            ]);

            Craft::error($error, __METHOD__);
        }

        // Restore original values
        Craft::$app->language = $originalLanguage;
        $templatesService->setTemplateMode($oldTemplateMode);
    }

    /**
     * Event: before sending email
     * Event params:    craftEmail(EmailModel)
     *                  commerceEmail(Email)
     *                  order(Order)
     *                  orderHistory(OrderHistory)
     *
     * @param \CEvent $event
     *
     * @throws \CException
     */
    public function onBeforeSendEmail(\CEvent $event)
    {
        $params = $event->params;

        if (empty($params['craftEmail']) || !($params['craftEmail'] instanceof EmailModel)) {
            throw new Exception('onBeforeSendEmail event requires "craftEmail" param with EmailModel instance');
        }

        if (empty($params['commerceEmail']) || !($params['commerceEmail'] instanceof Email)) {
            throw new Exception('onBeforeSendEmail event requires "commerceEmail" param with Email instance');
        }

        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            throw new Exception('onBeforeSendEmail event requires "order" param with Order instance');
        }

        if (empty($params['orderHistory']) || !($params['orderHistory'] instanceof OrderHistory)) {
            throw new Exception('onBeforeSendEmail event requires "orderHistory" param with OrderHistory instance');
        }

        $this->raiseEvent('onBeforeSendEmail', $event);
    }

    /**
     * Event: before sending email
     * Event params:    craftEmail(EmailModel)
     *                  commerceEmail(Email)
     *                  order(Order)
     *                  orderHistory(OrderHistory)
     *
     * @param \CEvent $event
     *
     * @throws \CException
     */
    public function onSendEmail(\CEvent $event)
    {
        $params = $event->params;

        if (empty($params['craftEmail']) || !($params['craftEmail'] instanceof EmailModel)) {
            throw new Exception('onSendEmail event requires "craftEmail" param with EmailModel instance');
        }

        if (empty($params['commerceEmail']) || !($params['commerceEmail'] instanceof Email)) {
            throw new Exception('onSendEmail event requires "commerceEmail" param with Email instance');
        }

        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            throw new Exception('onSendEmail event requires "order" param with Order instance');
        }

        if (empty($params['orderHistory']) || !($params['orderHistory'] instanceof OrderHistory)) {
            throw new Exception('onSendEmail event requires "orderHistory" param with OrderHistory instance');
        }

        $this->raiseEvent('onSendEmail', $event);
    }
}