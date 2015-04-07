<?php
/**
 * @package      ITPrism
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// No direct access
defined('_JEXEC') or die;

/**
 * This plugin deactivates user accounts.
 *
 * @package        ITPrism
 * @subpackage     Plugins
 */
class plgUserAccountDeactivation extends JPlugin
{
    const MAIL_MODE_HTML = true;
    const MAIL_MODE_PLAIN = false;

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Database object
     *
     * @var    JDatabaseDriver
     * @since  3.2
     */
    protected $db;

    /**
     * This method should handle any login logic and report back to the subject
     *
     * @param   array $user    Holds the user data
     * @param   array $options Array holding options (remember, autoregister, group)
     *
     * @return  boolean  True on success
     * @since   1.5
     */
    public function onUserLogin($user, $options)
    {
        $days = (int)$this->params->get("days", 0);

        if (!$days) {
            return true;
        }

        $query = $this->db->getQuery(true);

        $query
            ->select("a.id, a.name, a.email")
            ->from($this->db->quoteName("#__users", "a"))
            ->where($this->db->quoteName("email") . " = " . $this->db->quote($user["email"]))
            ->where($this->db->quoteName("registerDate") . " <= DATE_SUB(CURDATE(), INTERVAL ".(int)$days." DAY)");

        $this->db->setQuery($query, 0, 1);
        $result = (array)$this->db->loadAssoc();

        // Block user.
        if (!empty($result)) {

            // Do not block administrators.
            $user = JFactory::getUser($result["id"]);
            if ($user->authorise('core.admin')) {
                return true;
            }

            $query = $this->db->getQuery(true);

            $query
                ->update($this->db->quoteName("#__users"))
                ->set($this->db->quoteName("block") . " = 1")
                ->where($this->db->quoteName("id") . " = " . (int)$result["id"]);

            $this->db->setQuery($query);
            $this->db->execute();

            // Sending notification mails.
            if ($this->params->get("sending_mails", 0)) {
                $this->sendMails($result, $this->params);
            }
        }

        return true;
    }

    /**
     * Send emails to the administrator and user.
     *
     * @param array $user
     * @param Joomla\Registry\Registry $params
     */
    protected function sendMails($user, $params)
    {
        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        // Get website
        $uri       = JUri::getInstance();
        $website   = $uri->toString(array("scheme", "host"));

        $emailMode = $this->params->get("email_mode", "plain");

        // Prepare data for parsing.
        $data = array(
            "site_name"  => $app->get("sitename"),
            "site_url"   => JUri::root(),
            "name"       => $user["name"],
            "user_url"   => $website . "/administrator/index.php?option=com_users&view=users&filter_search=".rawurlencode($user["name"])
        );


        // Send e-mails to the user and to the administrator.
        jimport("emailtemplates.init");

        // Send mail to the administrator
        $emailId = $this->params->get("send_to_administrator", 0);
        if (!empty($emailId)) {

            // Load e-mail templates.
            $email = new EmailTemplates\Email();
            $email->setDb(JFactory::getDbo());
            $email->load($emailId);

            if (!$email->getSenderName()) {
                $email->setSenderName($app->get("fromname"));
            }
            if (!$email->getSenderEmail()) {
                $email->setSenderEmail($app->get("mailfrom"));
            }

            $recipientId = $params->get("administrator_id");
            if (!empty($recipientId)) {
                $recipient     = JFactory::getUser($recipientId);
                $recipientName = $recipient->get("name");
                $recipientMail = $recipient->get("email");
            } else {
                $recipientName = $app->get("fromname");
                $recipientMail = $app->get("mailfrom");
            }

            // Prepare data for parsing
            $data["sender_name"]     = $email->getSenderName();
            $data["sender_email"]    = $email->getSenderEmail();
            $data["recipient_name"]  = $recipientName;
            $data["recipient_email"] = $recipientMail;

            $email->parse($data);
            $subject = $email->getSubject();
            $body    = $email->getBody($emailMode);

            $mailer = JFactory::getMailer();
            if (strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, self::MAIL_MODE_HTML);
            } else { // Send as plain text.
                $return = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, self::MAIL_MODE_PLAIN);
            }

            // Check for an error.
            if ($return !== true) {
                JLog::add(JText::sprintf("PLG_USER_ACCOUNTDEACTIVATION_ERROR_MAIL_SENDING_ADMIN", $mailer->ErrorInfo));
            }

        }

        // Send mail to user.
        $emailId    = $this->params->get("send_to_user", 0);
        if (!empty($emailId)) {

            $email = new EmailTemplates\Email();
            $email->setDb(JFactory::getDbo());
            $email->load($emailId);

            if (!$email->getSenderName()) {
                $email->setSenderName($app->get("fromname"));
            }
            if (!$email->getSenderEmail()) {
                $email->setSenderEmail($app->get("mailfrom"));
            }

            // Prepare data for parsing
            $data["sender_name"]     = $email->getSenderName();
            $data["sender_email"]    = $email->getSenderEmail();
            $data["recipient_name"]  = $user["name"];
            $data["recipient_email"] = $user["email"];

            $email->parse($data);
            $subject = $email->getSubject();
            $body    = $email->getBody($emailMode);

            $mailer = JFactory::getMailer();
            if (strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $data["recipient_email"], $subject, $body, self::MAIL_MODE_HTML);
            } else { // Send as plain text.
                $return = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $data["recipient_email"], $subject, $body, self::MAIL_MODE_PLAIN);
            }

            // Check for an error.
            if ($return !== true) {
                JLog::add(JText::sprintf("PLG_USER_ACCOUNTDEACTIVATION_ERROR_MAIL_SENDING_USER", $mailer->ErrorInfo));
            }

        }

    }
}
