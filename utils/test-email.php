<?php
// Test if PHP mail() function works

echo "=== Email Function Test ===\n\n";

echo "Enter email address to send test to: ";
$email = trim(fgets(STDIN));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid email address\n");
}

$subject = "Test Email from Comment System";
$message = "This is a test email from your comment system.\n\n";
$message .= "If you receive this, email notifications are working!\n\n";
$message .= "Sent at: " . date('Y-m-d H:i:s') . "\n";

$headers = "From: noreply@" . gethostname() . "\r\n";
$headers .= "Reply-To: noreply@" . gethostname() . "\r\n";

echo "Sending test email to: $email\n";
echo "Subject: $subject\n\n";

$result = @mail($email, $subject, $message, $headers);

if ($result) {
    echo "✓ mail() function returned TRUE\n";
    echo "✓ Email should be delivered (check spam folder)\n";
    echo "\nNote: On localhost, mail() may not actually send.\n";
    echo "Upload to server and test there for real email delivery.\n";
} else {
    echo "✗ mail() function returned FALSE\n";
    echo "✗ Email was not sent\n\n";
    echo "Possible reasons:\n";
    echo "  - Not configured on this system (normal for localhost)\n";
    echo "  - Need to install/configure mail server\n";
    echo "  - Try on production server instead\n";
}

echo "\nDone!\n";
