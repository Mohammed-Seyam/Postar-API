{{-- Email title block: optional eyebrow (accent label) + Cairo 800 heading + optional lead. --}}
@props(['eyebrow' => null, 'lead' => null])
@if ($eyebrow)
    <p style="margin:0 0 6px; font-size:13px; font-weight:700; color:#8656b1;">{{ $eyebrow }}</p>
@endif
<h1 style="margin:0; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:22px; font-weight:800; line-height:1.45; color:#0b0b0f;">{{ $slot }}</h1>
@if ($lead)
    <p style="margin:8px 0 0; font-size:15px; line-height:1.8; color:#5b5b63;">{{ $lead }}</p>
@endif
<div style="height:24px; line-height:24px; font-size:0;">&nbsp;</div>
