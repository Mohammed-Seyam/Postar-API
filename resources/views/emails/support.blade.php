<!DOCTYPE html>
<html>
<head>
    <title>Support Request</title>
</head>
<body>
    <h2>New Support Request</h2>
    <p><strong>From:</strong> {{ $user->name }} ({{ $user->email }})</p>
    <hr>
    <p><strong>Message:</strong></p>
    <p>{{ $messageContent }}</p>
</body>
</html>
