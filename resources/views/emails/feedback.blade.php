@extends('emails.layouts.base', ['dir' => 'ltr'])

@section('title', 'New '.$type.' Received')

@section('content')
    <x-email.heading eyebrow="Feedback">New {{ $type }} received</x-email.heading>

    <p style="margin:0 0 4px; font-size:14px; color:#5b5b63;">From</p>
    <p style="margin:0 0 18px; font-weight:600; color:#0b0b0f;">{{ $user->name }} &lt;{{ $user->email }}&gt;</p>

    <p style="margin:0 0 4px; font-size:14px; color:#5b5b63;">Type</p>
    <p style="margin:0 0 18px;"><span style="display:inline-block; padding:3px 10px; border-radius:999px; background-color:#f9f6ff; color:#69438f; font-size:12px; font-weight:700;">{{ $type }}</span></p>

    <p style="margin:0 0 6px; font-size:14px; color:#5b5b63;">Message</p>
    <div dir="auto" style="padding:14px 16px; background-color:#f4f4f6; border-radius:12px; white-space:pre-wrap; color:#27272a;">{{ $messageContent }}</div>
@endsection
