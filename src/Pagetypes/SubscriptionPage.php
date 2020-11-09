<?php

namespace SilverStripe\Newsletter\Pagetypes;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\HTMLEditor\HtmlEditorField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Newsletter\Model\Recipient;
use SilverStripe\Newsletter\Model\MailingList;
use SilverStripe\Newsletter\Form\CheckboxSetWithExtraField;
use Page;
use SilverStripe\Newsletter\Control\NewsletterAdmin;
use SilverStripe\View\Requirements;

/**
 * Class SubscriptionPage
 * @package SilverStripe\Newsletter\Pagetypes
 *
 * @property string $Fields    comma separated list of selected fields
 * @property string $Required  JSON list of required fields e.g. { "Email":"1" }
 * @property string $CustomisedHeading  Text before the subscription form
 * @property string $CustomLabel    JSON Customized labels for the fields e.g. { "Email": "Email address" }
 * @property string $ValidationMessage  JSON customized validation message for fields
 *                                      e.g. { "Email": "An email address is required" }
 * @property string $MailingLists   JSON list of available mailing list IDs for this subscription e.g. { "1", "5" }
 * @property string $SubmissionButtonText Text to appear on the submit button (is not translated)
 * @property bool $SendNotification     send a notification email to subscriber
 * @property string $NotificationEmailSubject   Subject of the notification email
 * @property string $NotificationEmailFrom  Sender-Email for the notification email
 * @property string $OnCompleteMessage  Text to be shown when subscription process is completed
 */
class SubscriptionPage extends Page
{
    private static $db = [
        'Fields' => 'Text',
        'Required' => 'Text',
        'CustomisedHeading' => 'Text',
        'CustomLabel' => 'Text',
        'ValidationMessage' => 'Text',
        'MailingLists' => 'Text',
        'SubmissionButtonText' => 'Varchar',
        'SendNotification' => 'Boolean',
        'NotificationEmailSubject' => 'Varchar',
        'NotificationEmailFrom' => 'Varchar',
        'OnCompleteMessage' => 'HTMLText',
    ];

    private static $defaults = [
        'Fields' => 'Email',
        'SubmissionButtonText' => 'Submit'
    ];

    private static $singular_name = 'Newsletter Subscription Page';

    private static $plural_name = 'Newsletter Subscription Pages';

    private static $days_verification_link_alive = 2;

    private static $table_name = 'SubscriptionPage';

    private static $icon = 'silverstripe/newsletter:client/images/subscription-icon.png';

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        if (self::config()->get('create_default_pages')) {
            if (!SubscriptionPage::get()->Count()) {
                $page = SubscriptionPage::create();
                $page->Title = 'Newsletter Subscription';
                $page->URLSegment = 'newsletter-subscription';
                $page->SendNotification = 1;
                $page->ShowInMenus = false;
                $page->write();
                $page->publishRecursive();
            }
        }
    }

    /**
     * @return int configured number of days the subscription is valid
     *             defaults to 2 if less then 2 or not set in config.
     */
    public static function get_days_verification_link_alive()
    {
        return SubscriptionPage::$days_verification_link_alive;
    }
    /**
     * @InheritDoc
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields ->addFieldToTab(
            "Root",
            $subscriptionTab = new Tab(
                _t('Newsletter.SUBSCRIPTIONFORM', 'SubscriptionForm')
            )
        );

        $subscriptionTab->push(
            new HeaderField(
                "SubscriptionFormConfig",
                _t('Newsletter.SUBSCRIPTIONFORMCONFIGURATION', "Subscription Form Configuration")
            )
        );

        $subscriptionTab->push(
            new TextField('CustomisedHeading', 'Heading at the top of the form')
        );

        //Fields selection
        /** @var array $frontFields [ fieldName => Field ] */
        $frontFields = singleton(Recipient::class)->getFrontEndFields()->dataFields();

        $fieldCandidates = [];
        // put all selected fields on top of the list of the available fields
        $selectedFieldNames = explode(',', $this->Fields);
        if (empty($selectedFieldNames)) {
            $selectedFieldNames = ['Email'];
        }

        if (count($frontFields)) {
            foreach ($selectedFieldNames as $fieldName) {
                if (array_key_exists($fieldName, $frontFields)) {
                    $dataField = $frontFields[$fieldName];
                    $fieldCandidates[$fieldName] = $dataField->Title()?$dataField->Title():$dataField->Name();
                    unset($frontFields[$fieldName]);
                    $values[$fieldName] = $fieldName;
                }
            }
            foreach ($frontFields as $fieldName => $dataField) {
                $fieldCandidates[$fieldName]= $dataField->Title()?$dataField->Title():$dataField->Name();
            }
        }

        //Since Email field is the Recipient's identifier,
        //and newsletters subscription is non-sense if no email is given by the user,
        //we should force that email to be checked and required.
        //FirstName should be checked as default, though it might not be required
        $extra = array('CustomLabel'=>"Varchar","ValidationMessage"=>"Varchar","Required" =>"Boolean");
        $extraValue = array(
            'CustomLabel'=>$this->CustomLabel,
            "ValidationMessage"=>$this->ValidationMessage,
            "Required" =>$this->Required
        );

        $fieldsSelection = new CheckboxSetWithExtraField(
            "Fields",
            _t('Newsletter.SelectFields', "Select the fields to display on the subscription form"),
            $fieldCandidates,
            $selectedFieldNames,
            $extra,
            $extraValue
        );

        $subscriptionTab->push($fieldsSelection);
        // Email cannot be changed and is always required
        $fieldsSelection->setCellDisabled(array("Email"=>array("Value", "Required")));

        //Mailing Lists selection
        $mailinglists = MailingList::get();
        $newsletterSelection = $mailinglists && $mailinglists->count()?
        new CheckboxSetField(
            "MailingLists",
            _t("Newsletter.SubscribeTo", "Newsletters to subscribe to"),
            $mailinglists->map('ID', 'FullTitle'),
            $mailinglists
        ):
        new LiteralField(
            "NoMailingList",
            sprintf(
                '<p>%s</p>',
                sprintf(
                    'You haven\'t defined any mailing list yet, please go to '
                    . '<a href=\"%s\">the newsletter administration area</a> '
                    . 'to define a mailing list.',
                    singleton(NewsletterAdmin::class)->Link()
                )
            )
        );
        $subscriptionTab->push(
            $newsletterSelection
        );

        $subscriptionTab->push(
            new TextField("SubmissionButtonText", "Submit Button Text")
        );

        $subscriptionTab->push(
            new LiteralField(
                'DaysVerificationIsValid',
                sprintf(
                    '<div id="DaysVerificationIsValid">'.
                    _t('Newsletter.DaysVerificationIsValid', 'Validation for verification email is %d days').
                    '<br/></div>',
                    self::$days_verification_link_alive
                )
            )
        );

        Requirements::javascript('silverstripe/newsletter:client/javascript/SubscriptionPage.js');
        Requirements::css('silverstripe/newsletter:client/css/SubscriptionPage.css');
        $subscriptionTab->push(
            new LiteralField(
                'BottomTaskSelection',
                sprintf(
                    '<div id="SendNotificationControlls" class="field actions">'.
                    '<label class="left">%s</label>'.
                    '<ul><li class="ss-ui-button no" data-panel="no">%s</li>'.
                    '<li class="ss-ui-button yes" data-panel="yes">%s</li>'.
                    '</ul></div>',
                    _t('Newsletter.SendNotif', 'Send notification email to the subscriber'),
                    _t('Newsletter.No', 'No'),
                    _t('Newsletter.Yes', 'Yes')
                )
            )
        );


        $subscriptionTab->push(
            CompositeField::create(
                new HiddenField(
                    "SendNotification",
                    "Send Notification"
                ),
                new TextField(
                    "NotificationEmailSubject",
                    _t('Newsletter.NotifSubject', "Notification Email Subject Line")
                ),
                new TextField(
                    "NotificationEmailFrom",
                    _t('Newsletter.FromNotif', "From Email Address for Notification Email")
                )
            )->addExtraClass('SendNotificationControlledPanel')
        );

        $subscriptionTab->push(
            new HtmlEditorField(
                'OnCompleteMessage',
                _t('Newsletter.OnCompletion', 'Message shown on subscription completion')
            )
        );
        return $fields;
    }

    /**
     * Email field is the member's identifier, and newsletters subscription is
     * non-sense if no email is given by the user, we should force that email
     * to be checked and required.
     * @return string   JSON like {"Email":"1", "Surname": "1"}
     */
    public function getRequired()
    {
        return (!$this->getField('Required')) ? '{"Email":"1"}' : $this->getField('Required');
    }

    /**
     * @param FieldList $frontEndFields FieldList of fields to be shown at the front-end.
     *                                  This list are the fields for the Recipient without the excluded Fields
     *
     * @return FieldList    which contains only the selected fields for this Subscription Page in the selected order
     */
    public function getFrontendFieldList(FieldList  $frontEndFields)
    {
        if (empty($this->Fields)) {
            $this->Fields = self::$defaults['Fields'];
        }

        $selectedFieldNames = explode(',', $this->Fields);
        $selectedFrontEndFields = new FieldList();
        foreach ($selectedFieldNames as $fieldName) {
            $field = $frontEndFields->fieldByName($fieldName);
            if ($field instanceof FormField) {
                $selectedFrontEndFields->add($field);
            }
        }
        return $selectedFrontEndFields;
    }

    /**
     * @return array    list of required field names
     *                  'Email' is always required and added to the list if missing
     */
    public function getRequiredFieldNames()
    {
        $requiredFieldNames = [];
        $required = explode(',', trim($this->Required, '{}'));
        foreach ($required as $requiredField) {
            list($name, $one) = explode(':', $requiredField);
            $name = trim($name, '"');
            if (! empty($name)) {
                $requiredFieldNames[] = $name;
            }
        }
        // make sure that field Email is in the list
        if (! in_array('Email', $requiredFieldNames)) {
            $requiredFieldNames[] = 'Email';
        }
        return $requiredFieldNames;
    }
}
