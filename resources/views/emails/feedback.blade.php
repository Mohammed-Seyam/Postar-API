<!DOCTYPE html>
<html>
<head>
    <title>New {{ $type }}</title>
</head>
<body>
    <h2>New {{ $type }} Received</h2>
    <p><strong>From:</strong> {{ $user->name }} ({{ $user->email }})</p>
    <p><strong>Type:</strong> {{ $type }}</p>
    <hr>
    <p><strong>Message:</strong></p>
    <p>{{ $messageContent }}</p>
</body>
</html>
