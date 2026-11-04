<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <!--[if !mso]><!-- -->
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <!--<![endif]-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no" />
    <meta name="x-apple-disable-message-reformatting" />
    <link href="https://fonts.googleapis.com/css?family=Fira+Sans:ital,wght@0,400;0,500" rel="stylesheet" />
    <title>Verification Code</title>
    <!--[if !mso]><!-- -->
    <style>
        @font-face { font-family: 'Fira Sans'; font-style: normal; font-weight: 400; src: local('Fira Sans Regular'), local('FiraSans-Regular'), url(https://fonts.gstatic.com/s/firasans/v10/va9E4kDNxMZdWfMOD5Vvl4jLazX3dA.woff2) format('woff2'); }
        @font-face { font-family: 'Fira Sans'; font-style: normal; font-weight: 500; src: local('Fira Sans Medium'), local('FiraSans-Medium'), url(https://fonts.gstatic.com/s/firasans/v10/va9B4kDNxMZdWfMOD5VnZKveRhf6Xl7Glw.woff2) format('woff2'); }
    </style>
    <!--<![endif]-->
    <style>
        html, body { margin: 0 !important; padding: 0 !important; min-height: 100% !important; width: 100% !important; -webkit-font-smoothing: antialiased; }
        * { -ms-text-size-adjust: 100%; }
        table, td, th { mso-table-lspace: 0 !important; mso-table-rspace: 0 !important; border-collapse: collapse; }
        body, td, th, p, div, li, a, span { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; mso-line-height-rule: exactly; }
        img { border: 0; outline: 0; line-height: 100%; text-decoration: none; -ms-interpolation-mode: bicubic; }
        .pc-font-alt { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
</head>

<body class="body pc-font-alt" style="width: 100% !important; min-height: 100% !important; margin: 0 !important; padding: 0 !important; -webkit-font-smoothing: antialiased; background-color: #f4f4f4;" bgcolor="#f4f4f4">
<table style="table-layout: fixed; width: 100%; min-width: 600px; background-color: #f4f4f4;" bgcolor="#f4f4f4" border="0" cellspacing="0" cellpadding="0" role="presentation">
    <tr>
        <td align="center" valign="top">
            <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                    <td style="padding: 20px 0px 20px 0px;" align="left" valign="top">
                        <!-- Logo -->
                        <table style="width: 600px; max-width: 600px;" width="600" align="center" border="0" cellspacing="0" cellpadding="0" role="presentation">
                            <tr>
                                <td valign="top" style="padding: 36px 40px 36px 40px; background-color: #ffffff;" bgcolor="#ffffff">
                                    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                                        <tr>
                                            <td align="center" valign="top">
                                                <img src="{{ asset('assets/email/itp-logo.png') }}" style="display: block; outline: 0; line-height: 100%; height: 138px; max-height: 100%; width: auto; border: 0;" width="207" height="138" alt="it's totally possible" />
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                        <!-- Content -->
                        <table style="width: 600px; max-width: 600px;" width="600" align="center" border="0" cellspacing="0" cellpadding="0" role="presentation">
                            <tr>
                                <td style="padding: 0px 0px 23px 0px;" width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation">
                                    <table width="100%" align="center" border="0" cellspacing="0" cellpadding="0" role="presentation">
                                        <tr>
                                            <td valign="top" style="padding: 0px 60px 40px 60px; background-color: #ffffff;" bgcolor="#ffffff">
                                                <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                                                    <tr>
                                                        <td align="center" valign="top" style="padding: 0px 0px 33px 0px;">
                                                            <div style="font-size:26px;line-height:44px;text-align:center;color:#515151;font-family:'Fira Sans', Arial, Helvetica, sans-serif;letter-spacing:-0.2px;">
                                                                <span style="font-weight: 400;">Here is your verification code:</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <!-- Code Display -->
                                                <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                                                    <tr>
                                                        <td align="center" valign="top" style="padding: 0px 0px 30px 0px;">
                                                            <div style="font-size:48px;line-height:56px;text-align:center;color:#05133d;font-family:'Fira Sans', Arial, Helvetica, sans-serif;font-weight:500;letter-spacing:12px;">
                                                                {{ $code }}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                                                    <tr>
                                                        <td align="center" valign="top" style="padding: 0px 0px 20px 0px;">
                                                            <div style="font-size:16px;line-height:140%;text-align:center;color:#1b1b1b;font-family:'Fira Sans', Arial, Helvetica, sans-serif;letter-spacing:-0.2px;">
                                                                <span style="font-weight: 400;">This code expires in {{ $lifetimeMinutes }} minutes.</span>
                                                            </div>
                                                            <div style="font-size:16px;line-height:140%;text-align:center;color:#1b1b1b;font-family:'Fira Sans', Arial, Helvetica, sans-serif;letter-spacing:-0.2px;padding-top:16px;">
                                                                <span style="font-weight: 400;">If you did not request this code, no further action is required.</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>

</html>
