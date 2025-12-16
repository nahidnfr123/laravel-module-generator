{{-- resources/views/emails/verify-email.blade.php --}}
    <!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 20px;
        }
        p {
            margin-bottom: 16px;
        }
        .button {
            display: inline-block;
            padding: 14px 28px;
            background: #3490dc;
            color: white !important;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            margin: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e8e8e8;
            color: #666;
            font-size: 14px;
        }
    </style>
    <title>Verify Your Email Address</title>
</head>
<body>
<div class="container">
    <h1>Verify Your Email Address</h1>

    <p>Hello {{ $user->name }},</p>

    <p>Thank you for registering! Please click the button below to verify your email address.</p>

    <a href="{{ $redirectUrl }}" class="button">Verify Email Address</a>

    <p>If you did not create an account, no further action is required.</p>

    <div class="footer">
        <p>Thanks,<br>{{ config('app.name') }}</p>
    </div>
</div>
</body>
</html>
