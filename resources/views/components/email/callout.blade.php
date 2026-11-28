{{-- Tinted note box. variant: accent | warning | danger | success. --}}
@props(['variant' => 'accent'])
@php
    $palette = [
        'accent' => ['#f9f6ff', '#dfd3f0', '#27272a'],
        'warning' => ['#fef3c7', '#f5dc8a', '#713f12'],
        'danger' => ['#fee2e2', '#fbc4c4', '#991b1b'],
        'success' => ['#dcfce7', '#a7e3bb', '#14532d'],
    ][$variant] ?? ['#f9f6ff', '#dfd3f0', '#27272a'];
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0;">
    <tr>
        <td style="background-color:{{ $palette[0] }}; border:1px solid {{ $palette[1] }}; border-radius:12px; padding:14px 18px; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:14px; line-height:1.8; color:{{ $palette[2] }};">
            {{ $slot }}
        </td>
    </tr>
</table>
