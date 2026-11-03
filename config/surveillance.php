<?php

/*
 * The surveillance app's own settings. Everything that differs between this computer and the hosting server comes
 * from .env. The workbooks and private files hold patient and account details: keep them outside public/.
 */
return [
    // Folder of the workbooks: the AFP register, the IDSR stores, pictures.
    'data_dir' => env('DATA_DIR', storage_path('app/data')),

    // The AFP register workbook (default: DATA_DIR/AFP nofitication.xlsx).
    'afp_register' => env('AFP_REGISTER'),

    // Files never served directly: profile pictures, the remembered DHIMS2 token.
    'private_dir' => env('PRIVATE_DIR', storage_path('app/private')),

    // The secret in the setup page's link (/install?token=...), for hosting without a terminal. Empty: no setup page.
    'install_token' => env('INSTALL_TOKEN', ''),

    'support' => [
        'whatsapp' => '0246828504',
        'email' => 'babayahaya.mohammed@ghs.gov.gh',
    ],

    // SMS through Fish Africa (https://docs.letsfish.africa). The app ID and secret are under Settings >
    // Applications > Default App on the Fish Africa dashboard; the sender ID (up to 11 characters) must be approved there.
    'sms' => [
        'url' => env('LETSFISH_URL', 'https://api.letsfish.africa/v1/'),
        'app_id' => env('LETSFISH_APP_ID', ''),
        'app_secret' => env('LETSFISH_APP_SECRET', ''),
        'sender_id' => env('LETSFISH_SENDER_ID', 'GHS-AFP'),
        // A case's SMS can be sent again only after this many hours, so a double click never texts twice.
        'repeat_hours' => (int) env('SMS_REPEAT_HOURS', 24),
    ],
];
