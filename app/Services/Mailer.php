<?php

namespace FluentComments\App\Services;

class Mailer
{
    private $subject = '';

    private $body = '';

    private $to = '';

    private $from = '';

    private $cc = [];

    private $bcc = [];

    private $replyTo = '';

    private $isHtml = true;

    public function __construct($to = '', $subject = '', $body = '')
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;

        $this->setDefaultHeaders();
    }

    /**
     * From and Reply-To as set on the email template screen.
     *
     * Left empty unless somebody filled them in, so by default wp_mail()
     * keeps deciding - which is what an SMTP plugin expects, and what a
     * site with a properly configured sender address wants.
     */
    public function setDefaultHeaders($settings = null)
    {
        if ($settings === null) {
            $settings = EmailService::getTemplateSettings();
        }

        if (!empty($settings['from_email']) && is_email($settings['from_email'])) {
            $this->from = $this->addressHeader($settings['from_name'], $settings['from_email']);
        }

        if (!empty($settings['reply_to_email']) && is_email($settings['reply_to_email'])) {
            $this->replyTo = $this->addressHeader($settings['reply_to_name'], $settings['reply_to_email']);
        }

        return $this;
    }

    /**
     * A display name reaches a mail header, so anything that could start a
     * second one is stripped.
     *
     * @param string $name
     * @param string $email
     * @return string
     */
    private function addressHeader($name, $email)
    {
        $name = trim(str_replace(['"', "\r", "\n"], '', (string)$name));

        return $name ? '"' . $name . '" <' . $email . '>' : $email;
    }

    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }

    public function to($email, $name = '')
    {
        $email = sanitize_email($email);

        // Comment author names end up in a mail header, so strip anything
        // that could be used to inject one.
        $name = trim(str_replace(['"', "\r", "\n"], '', (string)$name));

        if ($name) {
            $this->to = '"' . $name . '" <' . $email . '>';
        } else {
            $this->to = $email;
        }

        return $this;
    }

    public function setIsHtml($isHtml)
    {
        $this->isHtml = $isHtml;
        return $this;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function addCC($cc)
    {
        $this->cc[] = $cc;
        return $this;
    }

    public function addBCC($bcc)
    {
        $this->bcc[] = $bcc;
        return $this;
    }

    public function setReplyTo($replyTo)
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    public function send()
    {
        if (!$this->to && !$this->cc && !$this->bcc) {
            return false;
        }

        $headers = [];

        if ($this->isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        if ($this->from) {
            $headers[] = 'From: ' . $this->from;
        }

        if ($this->cc) {
            $headers[] = 'Cc: ' . implode(',', $this->cc);
        }

        if ($this->bcc) {
            $headers[] = 'Bcc: ' . implode(',', $this->bcc);
        }

        if ($this->replyTo) {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        $headers = apply_filters('fluent_comments/mail_headers', $headers, $this);

        return wp_mail($this->to, $this->subject, $this->body, $headers);
    }
}
