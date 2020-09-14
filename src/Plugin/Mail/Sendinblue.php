<?php

namespace Drupal\sendinblue_api\Plugin\Mail;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Render\Markup;
use SendinBlue\Client;
use SendinBlue\Client\Api;
use SendinBlue\Client\Model;
use SendinBlue\Client\Model\SendSmtpEmailSender;

/**
 * Defines the default Drupal mail backend, using Sendinblue.
 *
 * @Mail(
 *   id = "sendinblue",
 *   label = @Translation("Sendinblue"),
 *   description = @Translation("Sends the message as plain text, using Sendinblue")
 * )
 */
class Sendinblue implements MailInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * PhpMail constructor.
   */
  public function __construct() {
    $this->configFactory = \Drupal::configFactory();
  }

  /**
   * Concatenates and wraps the email body for plain-text mails.
   *
   * @param array $message
   *   A message array, as described in hook_mail_alter().
   *
   * @return array
   *   The formatted $message.
   */
  public function format(array $message) {
    // Join the body array into one string.
    $message['body'] = implode("\n\n", $message['body']);

    // Convert any HTML to plain-text.
    $message['body'] = MailFormatHelper::htmlToText($message['body']);
    // Wrap the mail body for sending.
    $message['body'] = MailFormatHelper::wrapMail($message['body']);

    return $message;
  }

  /**
   * Sends an email message.
   *
   * @param array $message
   *   A message array, as described in hook_mail_alter().
   *
   * @return bool
   *   TRUE if the mail was successfully accepted, otherwise FALSE.
   *
   * @see http://php.net/manual/function.mail.php
   * @see \Drupal\Core\Mail\MailManagerInterface::mail()
   */
  public function mail(array $message) {
    // If 'Return-Path' isn't already set in php.ini, we pass it separately
    // as an additional parameter instead of in the header.
    if (isset($message['headers']['Return-Path'])) {
      $return_path_set = strpos(ini_get('sendmail_path'), ' -f');
      if (!$return_path_set) {
        $message['Return-Path'] = $message['headers']['Return-Path'];
        unset($message['headers']['Return-Path']);
      }
    }
    $mimeheaders = [];
    foreach ($message['headers'] as $name => $value) {
      $mimeheaders[] = $name . ': ' . Unicode::mimeHeaderEncode($value);
    }
    $line_endings = Settings::get('mail_line_endings', PHP_EOL);
    // Prepare mail commands.
    $mail_subject = Unicode::mimeHeaderEncode($message['subject']);
    // Note: email uses CRLF for line-endings. PHP's API requires LF
    // on Unix and CRLF on Windows. Drupal automatically guesses the
    // line-ending format appropriate for your system. If you need to
    // override this, adjust $settings['mail_line_endings'] in settings.php.
    $mail_body = preg_replace('@\r?\n@', $line_endings, $message['body']);
    // For headers, PHP's API suggests that we use CRLF normally,
    // but some MTAs incorrectly replace LF with CRLF. See #234403.
    $mail_headers = implode("\n", $mimeheaders);

    $request = \Drupal::request();

    // We suppress warnings and notices from mail() because of issues on some
    // hosts. The return value of this method will still indicate whether mail
    // was sent successfully.
    if (!$request->server->has('WINDIR') && strpos($request->server->get('SERVER_SOFTWARE'), 'Win32') === FALSE) {
      // On most non-Windows systems, the "-f" option to the sendmail command
      // is used to set the Return-Path. There is no space between -f and
      // the value of the return path.
      // We validate the return path, unless it is equal to the site mail, which
      // we assume to be safe.
      $site_mail = \Drupal::config('system.site')->get('mail_notification');
      if (empty($site_mail)){
        $site_mail = $this->configFactory->get('system.site')->get('mail');
      }
      $additional_headers = isset($message['Return-Path']) && ($site_mail === $message['Return-Path'] || static::_isShellSafe($message['Return-Path'])) ? '-f' . $message['Return-Path'] : '';


      $attachments = $message['params']['attachments'] ?? [];

      // Get Sendinblue Configuration.
      $sendinblue_config = \Drupal::config('sendinblue_api.config');
      $config = \SendinBlue\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', $sendinblue_config->get('api_key'));
      $apiInstance = new \SendinBlue\Client\Api\SMTPApi(
          // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
          // This is optional, `GuzzleHttp\Client` will be used as default.
          new \GuzzleHttp\Client(),
          $config
      );

      $sendSmtpEmail = new \SendinBlue\Client\Model\SendSmtpEmail(); // \SendinBlue\Client\Model\SendSmtpEmail | Values to send a transactional email
      $sender = new SendSmtpEmailSender(['name' => $sendinblue_config->get('sender_name'), 'email' => $sendinblue_config->get('sender_email'),]);
      $sendSmtpEmail['sender'] = $sender;
      $sendSmtpEmail['to'] = array(array('email'=> $message['to']));
      $sendSmtpEmail['subject'] = $mail_subject;
      $sendSmtpEmail['htmlContent'] = $mail_body;

      try {
          $mail_result = $apiInstance->sendTransacEmail($sendSmtpEmail);
      } catch (Exception $e) {
          \Drupal::logger('sendinblue_api')->notice(t('Exception when calling SMTPApi->sendTransacEmail: ' . $e->getMessage()));
      }

    }
    else {
      // On Windows, PHP will use the value of sendmail_from for the
      // Return-Path header.
      $old_from = ini_get('sendmail_from');
      ini_set('sendmail_from', $message['Return-Path']);

      $mail_result = @mail(
        $message['to'],
        $mail_subject,
        $mail_body,
        $mail_headers
      );
      ini_set('sendmail_from', $old_from);
    }

    return $mail_result;
  }

  /**
   * Disallows potentially unsafe shell characters.
   *
   * Functionally similar to PHPMailer::isShellSafe() which resulted from
   * CVE-2016-10045. Note that escapeshellarg and escapeshellcmd are inadequate
   * for this purpose.
   *
   * @param string $string
   *   The string to be validated.
   *
   * @return bool
   *   True if the string is shell-safe.
   *
   * @see https://github.com/PHPMailer/PHPMailer/issues/924
   * @see https://github.com/PHPMailer/PHPMailer/blob/v5.2.21/class.phpmailer.php#L1430
   *
   * @todo Rename to ::isShellSafe() and/or discuss whether this is the correct
   *   location for this helper.
   */
  protected static function _isShellSafe($string) {
    if (escapeshellcmd($string) !== $string || !in_array(escapeshellarg($string), ["'$string'", "\"$string\""])) {
      return FALSE;
    }
    if (preg_match('/[^a-zA-Z0-9@_\-.]/', $string) !== 0) {
      return FALSE;
    }
    return TRUE;
  }

}
