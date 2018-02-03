<?php

include_once(__DIR__ . '/PHPMailer/class.phpmailer.php');

function template($filename, $replace = array(), $content = '') {
    if ($content == '') {
        $content = implode("", (file($filename)));
    }
    if (sizeof($replace) == 0) {
        return $content;
    } else {
        $replace_from = array();
        $replace_to = array();

        foreach ($replace as $from => $to) {
            if (!is_array($to)) {
                $replace_from[] = 'BW_' . strtoupper($from);
                $replace_to[] = $to;
            }
        }

        return str_replace($replace_from, $replace_to, $content);
    }
}

function emailCmp($emailA, $emailB) {
	$domainA = strtolower(substr(strrchr($emailA, "@"), 1));
	$domainB = strtolower(substr(strrchr($emailB, "@"), 1));
	
	if ($domainA == $domainB) {
        return 0;
    }
    return ($domainA < $domainB) ? -1 : 1;
}

function sendmail($emails, $betreff, $text, $from, $from_name, $filepath = '', $filename = '') {
    $mail = new PHPMailer;
    $mail->CharSet = 'utf-8';

	$mail->Subject = $betreff;
    $mail->setFrom($from, $from_name);
//     $mail->addReplyTo($return);
    
	if ($filepath != '') {
		$mail->addAttachment($filepath, $filename);
	}

    $mail->isHTML(false);
//    $mail->Body    = $HTMLtext;
//    $mail->AltBody = $text;
    $mail->Body = $text;

	if (!is_array($emails)) {
		$emails = array($emails);
	} else {
		usort($emails, 'emailCmp');
	}
	
	foreach ($emails as $email) {
		$mail->addAddress($email);
		$mail->send();
		$mail->clearAllRecipients();
	}
}

?>