<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حالة قائمة الانتظار | Waitlist Status</title>
</head>
<body style="font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    
    <div style="text-align: center; margin-bottom: 30px;">
        <h2>مرحباً بك في بوستار!</h2>
    </div>

    <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; text-align: center;">
        <p style="font-size: 16px; margin-bottom: 20px;">
            لقد طلبت رابط حالة قائمة الانتظار الخاص بك. يمكنك متابعة مركزك وعدد إحالاتك عبر الرابط أدناه:
        </p>
        
        <a href="{{ $statusUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; margin-bottom: 20px;">
            تحقق من حالتك الان
        </a>
        
        <p style="font-size: 14px; color: #666; margin-top: 10px;">
            إذا لم يعمل الزر، يمكنك نسخ هذا الرابط ولصقه في متصفحك:<br>
            <a href="{{ $statusUrl }}" style="color: #2563eb; word-break: break-all;">{{ $statusUrl }}</a>
        </p>
    </div>

    <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">

    <div style="text-align: left; direction: ltr;">
        <p>You requested your Waitlist status link. Click the button below to check your current position:</p>
        <p>
            <a href="{{ $statusUrl }}" style="color: #2563eb;">Check Your Status</a>
        </p>
    </div>

</body>
</html>
