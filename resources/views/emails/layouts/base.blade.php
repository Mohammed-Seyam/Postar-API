{{--
    Shared email layout. Follows the Postar design system (postar_interface/DESIGN_SYSTEM.md §6):
    light tokens as hex, table layout, inline styles, RTL.

    Sections: title (required), preheader, content (required), footer_note.
    Optional variable: $dir ('rtl' default, 'ltr' for English-only internal emails).
--}}
@php($dir = $dir ?? 'rtl')
@php($align = $dir === 'rtl' ? 'right' : 'left')
@php($appUrl = rtrim(config('app.frontend_url', 'https://postar.blog'), '/'))
<!DOCTYPE html>
<html lang="{{ $dir === 'rtl' ? 'ar' : 'en' }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title')</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap');
        a { color: #8656b1; }
        @media only screen and (max-width: 600px) {
            .p-card { padding: 28px 22px !important; }
            .w-full { width: 100% !important; }
            .btn-cell a { display: block !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#fafafa; -webkit-font-smoothing:antialiased;">
    {{-- Inbox preview text --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#fafafa;">@yield('preheader')&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="{{ $dir }}" style="background-color:#fafafa;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" class="w-full" style="width:560px; max-width:560px;">
                    {{-- Brand --}}
                    <tr>
                        <td align="{{ $align }}" style="padding:0 4px 18px;">
                            <a href="{{ $appUrl }}" style="text-decoration:none;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td width="30" height="30" align="center" valign="middle" style="width:30px; height:30px; background-color:#8656b1; border-radius:8px; color:#ffffff; font-size:13px; line-height:30px;">&#9654;&#xFE0E;</td>
                                        <td style="padding-{{ $dir === 'rtl' ? 'right' : 'left' }}:10px; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:20px; font-weight:800; color:#0b0b0f;">بوستار</td>
                                    </tr>
                                </table>
                            </a>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td class="p-card" align="{{ $align }}" style="background-color:#ffffff; border:1px solid #e7e7ea; border-radius:20px; padding:40px 36px; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:15px; line-height:1.85; color:#27272a; text-align:{{ $align }};">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:24px 12px 0; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:12px; line-height:1.8; color:#5b5b63; text-align:center;">
                            @hasSection('footer_note')
                                <p style="margin:0 0 6px;">@yield('footer_note')</p>
                            @endif
                            @if ($dir === 'rtl')
                                <p style="margin:0 0 6px;">لأي استفسار، تواصل معنا على <a href="mailto:support@postar.blog" style="color:#8656b1; text-decoration:none;"><span dir="ltr">support@postar.blog</span></a></p>
                                <p style="margin:0;">&copy; {{ date('Y') }} بوستار (Postar). جميع الحقوق محفوظة.</p>
                            @else
                                <p style="margin:0;">&copy; {{ date('Y') }} Postar. All rights reserved.</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
