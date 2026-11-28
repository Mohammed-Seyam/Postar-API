{{-- Bulletproof email button. variant: primary (brand fill) | secondary (outlined). --}}
@props(['href', 'variant' => 'primary', 'align' => 'center'])
@php($primary = $variant === 'primary')
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:28px 0;">
    <tr>
        <td align="{{ $align }}">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td class="btn-cell" align="center" style="border-radius:12px; background-color:{{ $primary ? '#8656b1' : '#ffffff' }};{{ $primary ? '' : ' border:1px solid #e7e7ea;' }}">
                        <a href="{{ $href }}" target="_blank" style="display:inline-block; padding:14px 28px; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:15px; font-weight:700; line-height:1.2; color:{{ $primary ? '#ffffff' : '#0b0b0f' }}; text-decoration:none; border-radius:12px;">{{ $slot }}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
