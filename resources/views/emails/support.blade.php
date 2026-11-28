@extends('emails.layouts.base', ['dir' => 'ltr'])

@section('title', 'New Support Request')

@section('content')
    <x-email.heading eyebrow="Support">New support request</x-email.heading>

    <p style="margin:0 0 4px; font-size:14px; color:#5b5b63;">From</p>
    <p style="margin:0 0 18px; font-weight:600; color:#0b0b0f;">{{ $user->name }} &lt;{{ $user->email }}&gt;</p>

    <p style="margin:0 0 6px; font-size:14px; color:#5b5b63;">Message</p>
    <div dir="auto" style="padding:14px 16px; background-color:#f4f4f6; border-radius:12px; white-space:pre-wrap; color:#27272a;">{{ $messageContent }}</div>
@endsection
